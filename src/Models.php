<?php

declare(strict_types=1);

namespace App;

/**
 * Bringing a model to the machine.
 *
 * Two facts shape this, and both are said in the window rather than buried:
 *
 *  - **A download leaves your machine.** Everything else here is loopback; asking
 *    for a model means fetching weights from a registry, which is a real outbound
 *    request. It happens only when the reader asks for it, by name.
 *  - **Size is knowable before you commit.** The registry publishes a manifest
 *    with a byte count per layer, so the weights can be sized against the card
 *    *before* several gigabytes arrive. The KV cache still cannot be — that needs
 *    the model's own metadata, which is inside the file — so the pre-download
 *    verdict says exactly what it knows and no more.
 *
 * The pull itself is done by Ollama, which is what makes it resumable and keeps
 * the blobs shared with every other tool the reader uses.
 */
final class Models
{
    public const int MAX_NAME = 120;

    public const int LOOKUP_TIMEOUT = 15;

    /** @var array<string, mixed>|null */
    private ?array $job = null;

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(
        private readonly Ollama $ollama,
        private readonly string $cachePath = '',
    ) {}

    /**
     * A short list worth offering, each with the reason it is on the list.
     *
     * Deliberately small. A hub with ten thousand entries is a different product;
     * this is the handful that does something the app cannot do otherwise.
     *
     * @return list<array{name: string, why: string}>
     */
    public function suggestions(): array
    {
        return [
            ['name' => 'qwen3:8b', 'why' => 'tools and reasoning, small enough to stay quiet'],
            ['name' => 'qwen3:30b-a3b', 'why' => 'much better at multi-step work; fits this card'],
            ['name' => 'llama3.2:3b', 'why' => 'quick answers when you do not need cleverness'],
            ['name' => 'moondream', 'why' => 'eyes: the vision model the picture features use'],
            ['name' => 'nomic-embed-text', 'why' => 'semantic search over your documents'],
        ];
    }

    /**
     * What a model weighs, asked of the registry.
     *
     * @return array{ok: bool, name?: string, megabytes?: int, gigabytes?: float, layers?: int, error?: string}
     */
    public function lookup(string $name): array
    {
        $name = \trim($name);

        // The shape of a real name: `name`, `namespace/name`, `name:tag`. A name
        // that looks like a path is refused rather than sent anywhere — it cannot
        // escape a URL built here, but it has no business reaching the network.
        $shape = '#^[A-Za-z0-9][A-Za-z0-9._-]*((/[A-Za-z0-9][A-Za-z0-9._-]*)?)(:[A-Za-z0-9][A-Za-z0-9._-]*)?$#';

        if ($name === '' || \strlen($name) > self::MAX_NAME || \str_contains($name, '..')
            || \preg_match($shape, $name) !== 1) {
            return ['ok' => false, 'error' => 'that is not a model name'];
        }

        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        [$repository, $tag] = \str_contains($name, ':') ? \explode(':', $name, 2) : [$name, 'latest'];

        // Ollama's registry namespaces the official library: "qwen3:8b" lives at
        // library/qwen3. Without that prefix every name answers 404, which is how
        // this was found — every model "did not exist".
        if (!\str_contains($repository, '/')) {
            $repository = 'library/' . $repository;
        }

        $url = 'https://registry.ollama.ai/v2/' . $repository . '/manifests/' . $tag;

        $context = \stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => self::LOOKUP_TIMEOUT,
            'ignore_errors' => true,
            'header' => "Accept: application/vnd.docker.distribution.manifest.v2+json\r\n",
        ]]);

        $body = @\file_get_contents($url, false, $context);

        if (!\is_string($body) || $body === '') {
            return ['ok' => false, 'error' => 'the registry did not answer for ' . $name];
        }

        $manifest = \json_decode($body, true);
        $layers = \is_array($manifest['layers'] ?? null) ? $manifest['layers'] : [];

        if ($layers === []) {
            return ['ok' => false, 'error' => 'no such model in the registry: ' . $name];
        }

        $bytes = 0;

        foreach ($layers as $layer) {
            if (\is_array($layer) && \is_numeric($layer['size'] ?? null)) {
                $bytes += (int) $layer['size'];
            }
        }

        $result = [
            'ok' => true,
            'name' => $name,
            'megabytes' => (int) \round($bytes / 1_048_576),
            'gigabytes' => \round($bytes / 1_073_741_824, 1),
            'layers' => \count($layers),
        ];

        $this->cache[$name] = $result;
        $this->writeCache();

        return $result;
    }

    /**
     * Start the download. Ollama does the fetching; this watches it.
     *
     * @return array{ok: bool, error?: string}
     */
    public function pull(string $name): array
    {
        $name = \trim($name);

        if ($name === '') {
            return ['ok' => false, 'error' => 'name a model to download'];
        }

        if ($this->job !== null) {
            return ['ok' => false, 'error' => 'a download is already running'];
        }

        foreach ($this->ollama->models() as $model) {
            if ($model['name'] === $name || \str_starts_with($model['name'], $name . ':')) {
                return ['ok' => false, 'error' => $name . ' is already installed'];
            }
        }

        try {
            $stream = $this->ollama->http()->open('POST', '/api/pull', ['name' => $name, 'stream' => true], 30.0);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $this->job = [
            'stream' => $stream,
            'name' => $name,
            'pending' => '',
            'status' => 'starting',
            'completed' => 0,
            'total' => 0,
            'error' => '',
            'started' => \microtime(true),
        ];

        return ['ok' => true];
    }

    /**
     * @return array{name: string, status: string, completed: int, total: int, percent: int, done: bool, error: string, seconds: float}|null
     */
    public function progress(): ?array
    {
        if ($this->job === null) {
            return null;
        }

        $bytes = $this->job['stream']->pump();

        if (\is_string($bytes) && $bytes !== '') {
            $this->job['pending'] .= $bytes;

            while (($newline = \strpos($this->job['pending'], "\n")) !== false) {
                $line = \trim(\substr($this->job['pending'], 0, $newline));
                $this->job['pending'] = \substr($this->job['pending'], $newline + 1);

                if ($line === '') {
                    continue;
                }

                $event = \json_decode($line, true);

                if (!\is_array($event)) {
                    continue;
                }

                if (isset($event['error'])) {
                    $this->job['error'] = (string) $event['error'];
                }

                if (isset($event['status']) && \is_string($event['status'])) {
                    $this->job['status'] = $event['status'];
                }

                if (\is_numeric($event['completed'] ?? null)) {
                    $this->job['completed'] = (int) $event['completed'];
                }

                if (\is_numeric($event['total'] ?? null) && (int) $event['total'] > 0) {
                    $this->job['total'] = (int) $event['total'];
                }
            }
        }

        $done = $this->job['stream']->finished();
        $percent = $this->job['total'] > 0 ? (int) \round($this->job['completed'] / $this->job['total'] * 100) : 0;

        return [
            'name' => (string) $this->job['name'],
            'status' => $done && $this->job['error'] === '' ? 'done' : (string) $this->job['status'],
            'completed' => (int) $this->job['completed'],
            'total' => (int) $this->job['total'],
            'percent' => \max(0, \min($percent, 100)),
            'done' => $done,
            'error' => (string) $this->job['error'],
            'seconds' => \round(\microtime(true) - (float) $this->job['started'], 1),
        ];
    }

    /** Close the job out, and say what happened. */
    public function finish(): array
    {
        if ($this->job === null) {
            return ['ok' => false, 'name' => '', 'error' => 'nothing was downloading', 'seconds' => 0.0];
        }

        $last = $this->progress() ?? [];
        $name = (string) ($this->job['name'] ?? '');
        $error = (string) ($this->job['error'] ?? '');

        $this->job['stream']->close();
        $this->job = null;

        return ['ok' => $error === '', 'name' => $name, 'error' => $error, 'seconds' => (float) ($last['seconds'] ?? 0)];
    }

    public function running(): bool
    {
        return $this->job !== null;
    }

    /** For the window: megabytes on disk, so installed models can be sized the same way. */
    public static function megabytesOf(array $model): int
    {
        return (int) \round(((int) ($model['size'] ?? 0)) / 1_048_576);
    }

    private function writeCache(): void
    {
        if ($this->cachePath === '') {
            return;
        }

        $directory = \dirname($this->cachePath);

        if (!\is_dir($directory)) {
            @\mkdir($directory, 0o755, true);
        }

        @\file_put_contents($this->cachePath, (string) \json_encode($this->cache, \JSON_PRETTY_PRINT));
    }
}
