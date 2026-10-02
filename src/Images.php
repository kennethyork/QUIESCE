<?php

declare(strict_types=1);

namespace App;

/**
 * Making images, on this machine, when there is something here to make them with.
 *
 * Two honest facts shape this class:
 *
 *  1. **Ollama cannot generate images.** A language model server has no diffusion
 *     model in it, so `/create_image` needs a local Stable Diffusion server —
 *     AUTOMATIC1111's `--api`, which the reader's own machine may or may not be
 *     running. When there is none, this says so and explains how to start one. It
 *     does not quietly reach for a cloud service, because that would break the one
 *     promise the rest of the application keeps.
 *  2. **Diffusion is the least quiet thing this app can do.** A 512×512 image at
 *     20 steps pins the card in a way a language model never does, so a request is
 *     refused while a model is generating and while the card is above the profile's
 *     ceiling. That gate lives in `Rpc::qImage`, where the governor's state is
 *     visible.
 *
 * The request runs in a worker process (`tools/generate.php`) rather than inside
 * the application loop: thirty seconds of diffusion must not freeze the window.
 */
final class Images
{
    public const string DEFAULT_URL = 'http://127.0.0.1:7860';

    public const int DEFAULT_STEPS = 20;

    public const int MAX_STEPS = 80;

    public const int DEFAULT_SIZE = 512;

    public const int MAX_PROMPT = 2_000;

    public const int KEEP = 200;

    private const string NEGATIVE = 'blurry, low quality, watermark, signature, text, deformed';

    /** @var array<string, mixed>|null */
    private ?array $job = null;

    /** When this app started the engine itself, rather than finding one running. */
    private bool $startedIt = false;

    /** 'image' or 'install' — both run in a worker, and the window shows both. */
    private string $kind = 'image';

    public function __construct(
        private readonly Settings $settings,
        private readonly string $directory,
        private readonly string $worker,
    ) {}

    /**
     * Where a script this app spawns actually is.
     *
     * In a compiled build `__DIR__` is a `phar://` path — inside the bundle — and
     * a shell cannot exec something inside a bundle. So the scripts are looked for
     * where they really can be on disk: beside the app when it runs from a
     * checkout, in the install directory it was launched from, or in the data
     * directory the installer writes to. Getting this wrong is not a cosmetic
     * failure: the image feature works in a checkout and is dead once installed,
     * which is exactly what the packaged build did until this was found.
     */
    public function tool(string $name): ?string
    {
        $home = (string) (\getenv('HOME') ?: '');
        $candidates = [
            \dirname($this->worker) . '/' . $name,
            \getcwd() . '/tools/' . $name,
            $home . '/.local/share/quiesce/tools/' . $name,
        ];

        foreach ($candidates as $candidate) {
            if (\str_starts_with($candidate, 'phar://')) {
                continue;
            }

            if (\is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** The script that finds and starts an engine, whichever one is installed. */
    public function starter(): ?string
    {
        return $this->tool('image-server.sh');
    }

    /** The worker that draws one image. */
    public function workerPath(): ?string
    {
        return $this->tool('generate.php');
    }

    /**
     * Start the local image engine — the sd.cpp that tools/install-engine.sh put
     * in the data directory, or whatever "image_command" names — bound to loopback. Blocks until it answers or gives up, because
     * "started" is not the same as "ready" and the reader should not have to find
     * that out by pressing Draw.
     *
     * @return array{ok: bool, output?: string, error?: string, url?: string}
     */
    public function serve(): array
    {
        if ($this->available()) {
            return ['ok' => true, 'output' => 'an image server is already answering at ' . $this->url()];
        }

        $starter = $this->starter();

        if ($starter === null) {
            return ['ok' => false, 'error' => 'the starter script is missing: looked beside the app, in the '
                . 'install directory, and in ~/.local/share/quiesce/tools — reinstall with tools/install-app.sh'];
        }

        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $environment = [
            'HOME' => (string) (\getenv('HOME') ?: '/tmp'),
            'PATH' => (string) (\getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'QUIESCE_IMAGE_PORT' => (string) (\parse_url($this->url(), \PHP_URL_PORT) ?: 7860),
        ];

        $configured = $this->settings->get('image_command');

        if (\is_string($configured) && \trim($configured) !== '') {
            $environment['QUIESCE_IMAGE_COMMAND'] = \trim($configured);
        }

        $process = @\proc_open(['/bin/sh', $starter], $descriptors, $pipes, \dirname($starter), $environment);

        if (!\is_resource($process)) {
            return ['ok' => false, 'error' => 'could not run the starter script'];
        }

        // Up to three minutes: an engine loading a checkpoint is slow, and this is
        // the one place where waiting is the honest thing to do.
        $output = '';
        $deadline = \microtime(true) + 200.0;

        while (\microtime(true) < $deadline) {
            $chunk = @\fread($pipes[1], 4_096);

            if (\is_string($chunk) && $chunk !== '') {
                $output .= $chunk;
            }

            $status = \proc_get_status($process);

            if (($status['running'] ?? false) === false) {
                $output .= (string) @\stream_get_contents($pipes[1]);
                $errors = (string) @\stream_get_contents($pipes[2]);

                foreach ($pipes as $pipe) {
                    if (\is_resource($pipe)) {
                        @\fclose($pipe);
                    }
                }

                @\proc_close($process);

                if ($this->available()) {
                    $this->startedIt = true;

                    return ['ok' => true, 'output' => \trim($output), 'url' => $this->url()];
                }

                return [
                    'ok' => false,
                    'error' => \trim($errors) !== '' ? \trim($errors) : 'the engine did not start',
                    'output' => \trim($output),
                ];
            }

            \usleep(250_000);
        }

        @\proc_terminate($process);

        return ['ok' => false, 'error' => 'the engine did not answer within 200s', 'output' => \trim($output)];
    }

    /** Stop the engine this app started. Leaves one the reader started alone. */
    public function stopServing(): array
    {
        $starter = $this->starter();

        if ($starter === null) {
            return ['ok' => false, 'error' => 'the starter script is missing'];
        }

        $output = @\shell_exec(\sprintf(
            'QUIESCE_IMAGE_PORT=%s sh %s --stop 2>&1',
            \escapeshellarg((string) (\parse_url($this->url(), \PHP_URL_PORT) ?: 7860)),
            \escapeshellarg($starter),
        ));

        $this->startedIt = false;

        return ['ok' => true, 'output' => \trim((string) $output)];
    }

    public function startedByUs(): bool
    {
        return $this->startedIt || \is_file(\dirname($this->worker) . '/.quiesce-image-ours');
    }

    /** @return array{available: bool, started_by_us: bool, url: string} */
    public function serving(): array
    {
        return [
            'available' => $this->available(),
            'started_by_us' => $this->startedByUs(),
            'url' => $this->url(),
        ];
    }

    /**
     * Where LoRA files live: the folder the engine is started with.
     *
     * Not next to the images — the engine is what has to see this directory, and
     * the engine lives in the data directory that tools/install-engine.sh writes
     * to. (It said ~/.config/quiesce/loras for a while, which is a folder the
     * engine would never look in.)
     */
    public function loraDirectory(): string
    {
        $configured = $this->settings->get('image_lora_dir');

        if (\is_string($configured) && $configured !== '') {
            return \rtrim($configured, '/');
        }

        $data = (string) (\getenv('XDG_DATA_HOME') ?: ((\getenv('HOME') ?: '/tmp') . '/.local/share'));

        return $data . '/quiesce/engine/loras';
    }

    /**
     * What is installed, and what the engine says it can load.
     *
     * Two lists on purpose: the files are what you have, the engine's list is what
     * it actually found when it scanned at startup — and the difference between
     * those two is the thing worth seeing.
     *
     * @return array{directory: string, files: list<string>, loaded: list<string>}
     */
    public function loras(): array
    {
        $directory = $this->loraDirectory();
        $files = [];

        foreach (\glob($directory . '/*.{safetensors,pt,ckpt}', \GLOB_BRACE) ?: [] as $file) {
            $files[] = \pathinfo($file, \PATHINFO_FILENAME);
        }

        \sort($files);

        $loaded = [];

        try {
            $response = $this->http()->json('GET', '/sdapi/v1/loras', null, 2.0);
            $entries = \is_array($response['json']) ? $response['json'] : [];

            foreach ($entries as $entry) {
                if (\is_array($entry) && \is_string($entry['name'] ?? null) && $entry['name'] !== '') {
                    $loaded[] = $entry['name'];
                }
            }
        } catch (\RuntimeException) {
            // The engine is not running; the folder is still worth reporting.
        }

        return ['directory' => $directory, 'files' => $files, 'loaded' => $loaded];
    }

    /**
     * Pull `<lora:name>` and `<lora:name:0.8>` out of a prompt.
     *
     * The tag syntax is the one people already type, and sd.cpp deliberately does
     * not parse it — so the app does, and hands the engine the structured list it
     * asked for instead. A tag is removed from the prompt either way: leaving it
     * in would send the engine a word that means nothing to it.
     *
     * @return array{prompt: string, loras: list<array{path: string, multiplier: float}>}
     */
    public static function parseLoras(string $prompt): array
    {
        $loras = [];

        $clean = \preg_replace_callback(
            '/<lora:([^>:]*)(?::(-?[0-9]*\.?[0-9]+))?>/i',
            static function (array $match) use (&$loras): string {
                $name = \trim($match[1]);

                if ($name === '') {
                    return '';
                }

                $multiplier = isset($match[2]) && $match[2] !== '' ? (float) $match[2] : 1.0;

                foreach ($loras as $existing) {
                    if ($existing['path'] === $name) {
                        return '';
                    }
                }

                $loras[] = ['path' => $name, 'multiplier' => \max(0.0, \min($multiplier, 2.0))];

                return '';
            },
            $prompt,
        ) ?? $prompt;

        // Tidy the gap a removed tag leaves behind.
        $clean = \preg_replace('/\s{2,}/', ' ', (string) $clean) ?? (string) $clean;

        return ['prompt' => \trim($clean), 'loras' => $loras];
    }

    /**
     * Turn what the reader typed into something the engine can open.
     *
     * `<lora:lcm:0.9>` names a LoRA the way people name them; the engine wants a
     * file it can read. So a bare name is looked up in the LoRA folder — and if it
     * is not there, it is passed through unchanged, because the engine's own
     * "invalid lora path" is a truer answer than the app inventing a file name.
     *
     * @param list<array{path: string, multiplier: float}> $loras
     *
     * @return list<array{path: string, multiplier: float}>
     */
    public static function resolveLoras(array $loras, string $directory): array
    {
        foreach ($loras as $index => $lora) {
            if (\str_contains($lora['path'], '/') || \is_file($lora['path'])) {
                continue;
            }

            foreach (['safetensors', 'pt', 'ckpt'] as $extension) {
                $candidate = \rtrim($directory, '/') . '/' . $lora['path'] . '.' . $extension;

                if (\is_file($candidate)) {
                    // Relative to the engine's LoRA folder: it refuses an absolute
                    // path with "invalid lora path", which is what it said here.
                    $loras[$index]['path'] = $lora['path'] . '.' . $extension;

                    break;
                }
            }
        }

        return $loras;
    }

    /** How the reader asked for LoRAs, for the log and the step list. */
    public static function describeLoras(array $loras): string
    {
        $parts = [];

        foreach ($loras as $lora) {
            $parts[] = $lora['path'] . ' at ' . \number_format((float) $lora['multiplier'], 2);
        }

        return \implode(', ', $parts);
    }

    public function directory(): string
    {
        if (!\is_dir($this->directory)) {
            @\mkdir($this->directory, 0o755, true);
        }

        return $this->directory;
    }

    public function url(): string
    {
        $url = $this->settings->get('image_url');

        return \is_string($url) && $url !== '' ? \rtrim($url, '/') : self::DEFAULT_URL;
    }

    /** @return array{ok: bool, url?: string, error?: string} */
    public function setUrl(?string $url): array
    {
        if ($url === null || \trim($url) === '') {
            $this->settings->set('image_url', '');
            $this->settings->save();

            return ['ok' => true, 'url' => self::DEFAULT_URL];
        }

        $parts = \parse_url($url);

        if (!\is_array($parts) || !\in_array(\strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return ['ok' => false, 'error' => 'that is not an http(s) address'];
        }

        if (!\in_array((string) $parts['host'], Http::LOOPBACK, true)) {
            return ['ok' => false, 'error' => 'only a server on this machine can be used (' . \implode(', ', Http::LOOPBACK) . ')'];
        }

        $this->settings->set('image_url', \rtrim($url, '/'));
        $this->settings->save();

        return ['ok' => true, 'url' => \rtrim($url, '/')];
    }

    public function steps(): int
    {
        $steps = (int) ($this->settings->get('image_steps') ?? self::DEFAULT_STEPS);

        return \max(1, \min($steps, self::MAX_STEPS));
    }

    public function setSteps(int $steps): void
    {
        $this->settings->set('image_steps', \max(1, \min($steps, self::MAX_STEPS)));
        $this->settings->save();
    }

    public function size(): int
    {
        $size = (int) ($this->settings->get('image_size') ?? self::DEFAULT_SIZE);

        return \max(256, \min($size, 1_024));
    }

    public function setSize(int $size): void
    {
        $this->settings->set('image_size', \max(256, \min($size, 1_024)));
        $this->settings->save();
    }

    /**
     * Is there a diffusion server on this machine?
     *
     * @return array{available: bool, url: string, kind: string, models: list<string>, error: string}
     */
    public function backend(): array
    {
        $url = $this->url();
        $base = ['url' => $url, 'kind' => 'none', 'models' => [], 'error' => ''];

        try {
            $http = new Http((string) \parse_url($url, \PHP_URL_HOST), (int) (\parse_url($url, \PHP_URL_PORT) ?: 80));
        } catch (\RuntimeException $e) {
            return ['available' => false, 'error' => $e->getMessage()] + $base;
        }

        // AUTOMATIC1111 and the servers that copy its API answer both of these.
        foreach (['/sdapi/v1/sd-models', '/sdapi/v1/options'] as $path) {
            try {
                $response = $http->json('GET', $path, null, 3.0);
            } catch (\RuntimeException $e) {
                return ['available' => false, 'error' => $e->getMessage()] + $base;
            }

            if ($response['status'] === 200) {
                $models = [];

                foreach (\is_array($response['json']) ? $response['json'] : [] as $model) {
                    if (\is_array($model) && \is_string($model['model_name'] ?? null)) {
                        $models[] = $model['model_name'];
                    }
                }

                return [
                    'available' => true,
                    'kind' => $path === '/sdapi/v1/sd-models' ? 'a1111' : 'a1111 (options only)',
                    'models' => \array_slice($models, 0, 6),
                    'error' => '',
                    'url' => $url,
                ];
            }
        }

        return ['available' => false, 'error' => 'nothing answered at ' . $url] + $base;
    }

    public function available(): bool
    {
        return $this->backend()['available'];
    }

    /**
     * Install the engine — stable-diffusion.cpp, MIT — as a job the window can watch.
     *
     * The point of this is the fresh machine: the app should be able to bring its
     * own engine, rather than assuming one is already installed somewhere. It
     * takes minutes and prints progress, so it runs in a worker and the window
     * shows what it is doing — an install that prints nothing for four minutes
     * looks exactly like a hang.
     *
     * @return array{ok: bool, error?: string}
     */
    public function install(string $engine = 'sd'): array
    {
        if ($this->job !== null) {
            return ['ok' => false, 'error' => 'something else is already running'];
        }

        if ($engine !== 'sd') {
            return ['ok' => false, 'error' => 'there is one engine: sd (stable-diffusion.cpp)'];
        }

        $script = $this->tool('install-engine.sh');

        if ($script === null) {
            return ['ok' => false, 'error' => 'the engine installer is missing: looked beside the app, in the '
                . 'install directory, and in ~/.local/share/quiesce/tools — reinstall with tools/install-app.sh'];
        }

        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = @\proc_open(
            ['/bin/sh', $script, '--engine=' . $engine],
            $descriptors,
            $pipes,
            \dirname($script),
            [
                'HOME' => (string) (\getenv('HOME') ?: '/tmp'),
                'PATH' => (string) (\getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
                'QUIESCE_IMAGE_PORT' => (string) (\parse_url($this->url(), \PHP_URL_PORT) ?: 7860),
            ],
        );

        if (!\is_resource($process)) {
            return ['ok' => false, 'error' => 'could not run the installer'];
        }

        foreach ([1, 2] as $fd) {
            \stream_set_blocking($pipes[$fd], false);
        }

        $this->kind = 'install';
        $this->job = [
            'process' => $process,
            'pipes' => $pipes,
            'error' => '',
            'command' => 'install-engine --engine=' . $engine,
            'started' => \microtime(true),
            'deadline' => \microtime(true) + 3_600,
            'timeout' => 3_600,
            'output' => '',
            'truncated' => false,
            'done' => false,
            'code' => null,
        ];

        return ['ok' => true];
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /** The last line the worker printed, for a status the reader can see moving. */
    public function latest(): string
    {
        $lines = \array_filter(\explode("\n", \trim((string) ($this->job['output'] ?? ''))));

        $line = '';

        foreach (\array_reverse($lines) as $candidate) {
            $candidate = \trim($candidate);

            // Skip curl's progress bars: they are one line of hashes each.
            if ($candidate !== '' && !\str_contains($candidate, '#') && !\preg_match('/^[0-9.]+%$/', $candidate)) {
                $line = $candidate;

                break;
            }
        }

        return \substr($line, 0, 160);
    }

    /**
     * Start a generation in a worker process.
     *
     * @return array{ok: bool, error?: string}
     */
    public function start(string $prompt): array
    {
        $parsed = self::parseLoras(\trim($prompt));
        $prompt = $parsed['prompt'];

        if ($prompt === '' && $parsed['loras'] === []) {
            return ['ok' => false, 'error' => 'nothing to draw'];
        }

        if (\strlen($prompt) > self::MAX_PROMPT) {
            return ['ok' => false, 'error' => 'that prompt is longer than ' . self::MAX_PROMPT . ' characters'];
        }

        if (!$this->available()) {
            return ['ok' => false, 'error' => 'no local image server is answering at ' . $this->url()];
        }

        $payload = [
            'url' => $this->url(),
            'prompt' => $prompt,
            'loras' => self::resolveLoras($parsed['loras'], $this->loraDirectory()),
            'sampler' => (string) ($this->settings->get('image_sampler') ?: 'Euler a'),
            'cfg' => (float) ($this->settings->get('image_cfg') ?: 7),
            'negative' => self::NEGATIVE,
            'steps' => $this->steps(),
            'size' => $this->size(),
            'directory' => $this->directory(),
        ];

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $worker = $this->workerPath();

        if ($worker === null) {
            return ['ok' => false, 'error' => 'the image worker is missing: looked beside the app, in the install '
                . 'directory, and in ~/.local/share/quiesce/tools — reinstall with tools/install-app.sh'];
        }

        $process = @\proc_open(
            [\PHP_BINARY, $worker, \base64_encode((string) \json_encode($payload))],
            $descriptors,
            $pipes,
            \dirname($worker),
        );

        if (!\is_resource($process)) {
            return ['ok' => false, 'error' => 'could not start the image worker'];
        }

        foreach ([1, 2] as $fd) {
            \stream_set_blocking($pipes[$fd], false);
        }

        $this->kind = 'image';
        $this->job = [
            'process' => $process,
            'pipes' => $pipes,
            'prompt' => $prompt,
            'loras' => $parsed['loras'],
            'output' => '',
            'error' => '',
            'started' => \microtime(true),
        ];

        return ['ok' => true];
    }

    /**
     * @return array{running: bool, output: string, error: string, seconds: float}
     */
    public function poll(): array
    {
        if ($this->job === null) {
            return ['running' => false, 'output' => '', 'error' => '', 'seconds' => 0.0];
        }

        foreach ([1, 2] as $fd) {
            $chunk = @\fread($this->job['pipes'][$fd], 65_536);

            if (\is_string($chunk) && $chunk !== '') {
                $this->job[$fd === 1 ? 'output' : 'error'] .= $chunk;
            }
        }

        $status = \proc_get_status($this->job['process']);

        return [
            'running' => (bool) ($status['running'] ?? false),
            'output' => (string) $this->job['output'],
            'error' => (string) $this->job['error'],
            'seconds' => \round(\microtime(true) - $this->job['started'], 1),
        ];
    }

    /**
     * The worker's verdict: which files it wrote, or why it could not.
     *
     * @return array{ok: bool, files: list<string>, error: string, seconds: float}
     */
    public function collect(): array
    {
        if ($this->job === null) {
            return ['ok' => false, 'files' => [], 'error' => 'nothing was running', 'seconds' => 0.0];
        }

        $seconds = \round(\microtime(true) - $this->job['started'], 1);
        $raw = \trim((string) $this->job['output']);

        foreach ([1, 2] as $fd) {
            if (\is_resource($this->job['pipes'][$fd])) {
                @\fclose($this->job['pipes'][$fd]);
            }
        }

        @\proc_close($this->job['process']);
        $this->job = null;

        // The worker prints one JSON object; anything before it is noise.
        $line = '';

        foreach (\array_reverse(\explode("\n", $raw)) as $candidate) {
            if (\str_starts_with(\trim($candidate), '{')) {
                $line = \trim($candidate);

                break;
            }
        }

        if ($this->kind === 'install') {
            // The installer prints prose, not JSON: success is its exit code, and
            // its output is the log the reader has been watching.
            $code = (int) ($this->job['code'] ?? 0);
            $tail = \trim(\substr($raw, -1_200));

            return [
                'ok' => $code === 0,
                'files' => [],
                'error' => $code === 0 ? '' : ($tail === '' ? 'the installer failed' : $tail),
                'seconds' => $seconds,
            ];
        }

        $decoded = $line === '' ? null : \json_decode($line, true);

        if (!\is_array($decoded)) {
            return [
                'ok' => false,
                'files' => [],
                'error' => \trim((string) $this->job['error'] ?? '') ?: 'the image worker produced no answer',
                'seconds' => $seconds,
            ];
        }

        return [
            'ok' => (bool) ($decoded['ok'] ?? false),
            'files' => \is_array($decoded['files'] ?? null) ? \array_map('\strval', $decoded['files']) : [],
            'error' => (string) ($decoded['error'] ?? ''),
            'seconds' => $seconds,
        ];
    }

    public function running(): bool
    {
        return $this->job !== null;
    }

    public function prompt(): string
    {
        return (string) ($this->job['prompt'] ?? '');
    }

    /** @return list<array{path: string, multiplier: float}> */
    public function requestedLoras(): array
    {
        return \is_array($this->job['loras'] ?? null) ? $this->job['loras'] : [];
    }

    /** The engine's own client, used for the discovery endpoints. */
    private function http(): Http
    {
        $host = (string) \parse_url($this->url(), \PHP_URL_HOST);
        $port = (int) (\parse_url($this->url(), \PHP_URL_PORT) ?: 80);

        return new Http($host === '' ? '127.0.0.1' : $host, $port);
    }

    public function stop(): void
    {
        if ($this->job === null) {
            return;
        }

        @\proc_terminate($this->job['process'], \SIGKILL);
        $this->job = null;
    }

    /**
     * How far along the server says it is, straight from its own progress API.
     *
     * @return array{progress: float, eta: float}|null
     */
    public function progress(): ?array
    {
        try {
            $http = new Http((string) \parse_url($this->url(), \PHP_URL_HOST), (int) (\parse_url($this->url(), \PHP_URL_PORT) ?: 80));
            $response = $http->json('GET', '/sdapi/v1/progress?skip_current_image=true', null, 1.5);
        } catch (\RuntimeException) {
            return null;
        }

        $progress = $response['json']['progress'] ?? null;

        return \is_numeric($progress)
            ? ['progress' => (float) $progress, 'eta' => (float) ($response['json']['eta_relative'] ?? 0.0)]
            : null;
    }

    /** @return list<array<string, mixed>> newest first */
    public function catalogue(int $limit = 40): array
    {
        $files = \glob($this->directory() . '/*.png') ?: [];
        \rsort($files);

        $images = [];

        foreach (\array_slice($files, 0, $limit) as $file) {
            $images[] = [
                'name' => \basename($file),
                'bytes' => (int) (@\filesize($file) ?: 0),
                'at' => (float) (@\filemtime($file) ?: 0),
            ];
        }

        return $images;
    }

    /** A file name inside the images folder, or null. Never a path. */
    public function path(string $name): ?string
    {
        if (\preg_match('/^[A-Za-z0-9._-]+\.png$/', $name) !== 1) {
            return null;
        }

        $path = $this->directory() . '/' . $name;

        return \is_file($path) ? $path : null;
    }

    /** Keep the folder bounded, the same way sessions are bounded. */
    public function prune(): void
    {
        $files = \glob($this->directory() . '/*.png') ?: [];

        if (\count($files) <= self::KEEP) {
            return;
        }

        \rsort($files);

        foreach (\array_slice($files, self::KEEP) as $old) {
            @\unlink($old);
        }
    }
}
