<?php

declare(strict_types=1);

namespace App;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Search over the reader's own documents.
 *
 * Two ways to find things, and it always says which one answered:
 *
 *  - **semantic**, when an embedding model is installed (an `*embed*` model in
 *    Ollama) and the folder has been indexed: chunks are embedded, cached next to
 *    the configuration, and ranked by cosine similarity against the embedded query;
 *  - **keyword**, otherwise: term frequency over chunked text, with a bonus when
 *    the words appear together.
 *
 * Shipping only the first would produce an agent that answers confidently from
 * nothing when the model is missing; shipping only the second would never find a
 * passage that shares no words with the question. So it does both and reports which.
 *
 * Scanning is bounded in every direction — file count, file size, total bytes
 * read — because a search that can walk a home directory is a search that can
 * hang the app.
 */
final class Documents
{
    public const int MAX_FILES = 3_000;

    public const int MAX_FILE_BYTES = 1_048_576;

    public const int MAX_SCAN_BYTES = 12_582_912;

    public const int CHUNK = 1_200;

    public const int OVERLAP = 200;

    /** Extensions worth reading; anything else is treated as binary. */
    private const array TEXT = [
        'md', 'markdown', 'txt', 'text', 'rst', 'org', 'json', 'jsonl', 'csv', 'tsv',
        'yml', 'yaml', 'toml', 'ini', 'cfg', 'conf', 'env', 'sql', 'log',
        'php', 'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx', 'py', 'rb', 'go', 'rs', 'java',
        'c', 'h', 'cpp', 'hpp', 'cs', 'sh', 'bash', 'zsh', 'fish', 'css', 'scss', 'html',
        'htm', 'xml', 'svg', 'tex', 'lua', 'pl', 'r', 'kt', 'swift', 'vue', 'svelte',
    ];

    /** Directories that are never worth walking. */
    private const array SKIP = [
        '.git', '.svn', '.hg', 'node_modules', 'vendor', 'dist', 'build', '.cache',
        '__pycache__', '.venv', 'venv', 'target', '.idea', '.vscode', 'coverage',
    ];

    /** @var array<string, list<array{line: int, text: string}>> */
    private array $cache = [];

    /** Longest we will let a stored vector list grow, in chunks. */
    public const int MAX_VECTORS = 2_000;

    public function __construct(
        private readonly Settings $settings,
        private readonly ?Ollama $ollama = null,
        private readonly string $store = '',
    ) {}

    /**
     * @return array{ok: bool, output: string, results?: list<array<string, mixed>>}
     */
    /**
     * The embedding model to use, if one is installed.
     *
     * Asked of the engine rather than configured by hand: the reader should not
     * have to know that semantic search needs a second model, and if it is there
     * the app should just use it.
     */
    public function embeddingModel(): ?string
    {
        $chosen = $this->settings->get('embed_model');

        if (\is_string($chosen) && $chosen !== '') {
            return $chosen;
        }

        if ($this->ollama === null) {
            return null;
        }

        foreach ($this->ollama->models() as $model) {
            $name = (string) $model['name'];

            if (\str_contains($name, 'embed') || \str_starts_with($name, 'all-minilm')) {
                return $name;
            }
        }

        return null;
    }

    /** @return array{chunks: int, vectors: int, model: ?string, semantic: bool} */
    public function indexStatus(): array
    {
        $store = $this->readStore();
        $root = $this->settings->get('workspace');
        $chunks = 0;

        if (\is_string($root) && \is_dir($root)) {
            foreach ($this->files($root) as $path) {
                $chunks += \count($this->chunks($path));
            }
        }

        return [
            'chunks' => $chunks,
            'vectors' => \count($store['vectors']),
            'model' => $store['model'] === '' ? $this->embeddingModel() : $store['model'],
            'semantic' => $this->embeddingModel() !== null,
        ];
    }

    /**
     * Embed what is not embedded yet. Bounded per call, so a big folder is
     * progress the reader can watch rather than a frozen window.
     *
     * @return array{ok: bool, done: int, remaining: int, error?: string}
     */
    public function index(int $limit = 64): array
    {
        $root = $this->settings->get('workspace');
        $model = $this->embeddingModel();

        if (!\is_string($root) || !\is_dir($root)) {
            return ['ok' => false, 'done' => 0, 'remaining' => 0, 'error' => 'no working folder has been chosen yet'];
        }

        if ($model === null) {
            return ['ok' => false, 'done' => 0, 'remaining' => 0, 'error' => 'no embedding model is installed (try: ollama pull nomic-embed-text)'];
        }

        $store = $this->readStore();

        if ($store['model'] !== '' && $store['model'] !== $model) {
            // A different model means different vectors: start again rather than
            // compare numbers that mean different things.
            $store = ['model' => $model, 'vectors' => []];
        }

        $store['model'] = $model;
        $pending = [];

        foreach ($this->files($root) as $path) {
            foreach ($this->chunks($path) as $index => $chunk) {
                $key = $this->key($root, $path, $index);

                if (isset($store['vectors'][$key])) {
                    continue;
                }

                $pending[] = ['key' => $key, 'text' => $chunk['text']];
            }
        }

        $batch = \array_slice($pending, 0, \max(1, $limit));

        if ($batch !== []) {
            $vectors = $this->embed(\array_column($batch, 'text'), $model, $root);

            if ($vectors === null) {
                return ['ok' => false, 'done' => 0, 'remaining' => \count($pending), 'error' => 'the embedding model did not answer'];
            }

            foreach ($batch as $index => $item) {
                if (isset($vectors[$index])) {
                    $store['vectors'][$item['key']] = self::pack($vectors[$index]);
                }
            }

            // Keep the store bounded: oldest keys go first.
            if (\count($store['vectors']) > self::MAX_VECTORS) {
                $store['vectors'] = \array_slice($store['vectors'], -self::MAX_VECTORS, null, true);
            }

            $this->writeStore($store);
        }

        return [
            'ok' => true,
            'done' => \count($batch),
            'remaining' => \max(0, \count($pending) - \count($batch)),
        ];
    }

    public function forgetIndex(): void
    {
        if ($this->store !== '' && \is_file($this->store)) {
            @\unlink($this->store);
        }
    }

    /**
     * Rank the stored vectors against the query.
     *
     * @return list<array<string, mixed>>|null null when there is nothing to rank with
     */
    private function semantic(string $query, int $limit): ?array
    {
        $store = $this->readStore();
        $model = $this->embeddingModel();
        $root = $this->settings->get('workspace');

        if ($model === null || $store['model'] !== $model || $store['vectors'] === [] || !\is_string($root)) {
            return null;
        }

        $queryVector = $this->embed([$query], $model, $root);

        if ($queryVector === null || !isset($queryVector[0])) {
            return null;
        }

        $query = $queryVector[0];
        $scored = [];

        foreach ($store['vectors'] as $key => $packed) {
            $vector = self::unpack((string) $packed);

            if ($vector === []) {
                continue;
            }

            $scored[] = ['key' => (string) $key, 'score' => self::cosine($query, $vector)];
        }

        \usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $results = [];

        foreach (\array_slice($scored, 0, \max(1, $limit)) as $hit) {
            [$path, , $index] = \explode(':', $hit['key'] . '::');

            $chunks = $this->chunks($root . '/' . $path);

            if (!isset($chunks[(int) $index])) {
                continue;
            }

            $results[] = [
                'path' => $path,
                'line' => $chunks[(int) $index]['line'],
                'score' => \round($hit['score'], 3),
                'text' => \trim(\mb_substr($chunks[(int) $index]['text'], 0, 700)),
            ];
        }

        return $results === [] ? null : $results;
    }

    /** @return list<list<float>>|null */
    private function embed(array $texts, string $model, string $root): ?array
    {
        if ($texts === []) {
            return [];
        }

        /*
         * Straight to Ollama, not through the governed endpoint — deliberately.
         *
         * The guard is single-threaded and stepped by the same loop this call is
         * made from, so a *blocking* request to it from here can never be
         * answered: the loop is waiting for the guard, and the guard is waiting
         * for the loop. Measured at 122 seconds before the fallback ran, which is
         * how this was found.
         *
         * It is also the right shape for the governor's purpose: an embedding is
         * a few milliseconds of GPU, not a generation, and the governor exists to
         * cap sustained work. Generations still go through the one door.
         */
        if ($this->ollama === null) {
            return null;
        }

        $targets = [$this->ollama->http()];

        foreach ($targets as $http) {
            try {
                $response = $http->json('POST', '/api/embed', ['model' => $model, 'input' => \array_values($texts)], 120.0);
            } catch (\RuntimeException) {
                continue;
            }

            $embeddings = $response['json']['embeddings'] ?? null;

            if ($response['status'] === 200 && \is_array($embeddings)) {
                return \array_values(\array_filter($embeddings, '\is_array'));
            }
        }

        return null;
    }

    /** @return array{model: string, vectors: array<string, string>} */
    private function readStore(): array
    {
        if ($this->store === '' || !\is_file($this->store)) {
            return ['model' => '', 'vectors' => []];
        }

        $decoded = \json_decode((string) @\file_get_contents($this->store), true);

        if (!\is_array($decoded)) {
            return ['model' => '', 'vectors' => []];
        }

        return [
            'model' => (string) ($decoded['model'] ?? ''),
            'vectors' => \is_array($decoded['vectors'] ?? null) ? $decoded['vectors'] : [],
        ];
    }

    /** @param array{model: string, vectors: array<string, string>} $store */
    private function writeStore(array $store): void
    {
        if ($this->store === '') {
            return;
        }

        $directory = \dirname($this->store);

        if (!\is_dir($directory)) {
            @\mkdir($directory, 0o755, true);
        }

        @\file_put_contents(
            $this->store,
            (string) \json_encode($store, \JSON_UNESCAPED_SLASHES),
            \LOCK_EX,
        );
    }

    private function key(string $root, string $path, int $index): string
    {
        $relative = \ltrim(\str_replace($root, '', $path), '/');
        $stamp = (int) (@\filemtime($path) ?: 0);

        return $relative . ':' . $stamp . ':' . $index;
    }

    /** @param list<float> $vector */
    public static function pack(array $vector): string
    {
        return \base64_encode(\pack('f*', ...\array_map('\floatval', \array_values($vector))));
    }

    /** @return list<float> */
    public static function unpack(string $packed): array
    {
        $binary = \base64_decode($packed, true);

        if (!\is_string($binary) || $binary === '' || \strlen($binary) % 4 !== 0) {
            return [];
        }

        $floats = \unpack('f*', $binary);

        return $floats === false ? [] : \array_values($floats);
    }

    /** @param list<float> $a @param list<float> $b */
    public static function cosine(array $a, array $b): float
    {
        $count = \min(\count($a), \count($b));

        if ($count === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }

        return ($na <= 0.0 || $nb <= 0.0) ? 0.0 : $dot / (\sqrt($na) * \sqrt($nb));
    }

    public function search(string $query, int $limit = 5): array
    {
        $root = $this->settings->get('workspace');

        if (!\is_string($root) || !\is_dir($root)) {
            return ['ok' => false, 'output' => 'no working folder has been chosen yet'];
        }

        $terms = self::terms($query);

        if ($terms === []) {
            return ['ok' => false, 'output' => 'nothing to search for'];
        }

        $semantic = $this->semantic($query, $limit);

        if ($semantic !== null) {
            $lines = [];

            foreach ($semantic as $hit) {
                $lines[] = '• ' . $hit['path'] . ' around line ' . $hit['line'] . "\n" . $hit['text'];
            }

            return [
                'ok' => true,
                'output' => \implode("\n\n", $lines)
                    . "\n\n(semantic search: " . (string) $this->embeddingModel() . ', locally)',
                'results' => $semantic,
            ];
        }

        $scanned = 0;
        $results = [];

        foreach ($this->files($root) as $path) {
            $size = @\filesize($path);

            if ($size === false || $size === 0 || $size > self::MAX_FILE_BYTES) {
                continue;
            }

            if ($scanned > self::MAX_SCAN_BYTES) {
                break;
            }

            $scanned += (int) $size;

            foreach ($this->chunks($path) as $chunk) {
                $score = self::score($chunk['text'], $terms);

                if ($score <= 0.0) {
                    continue;
                }

                $results[] = [
                    'path' => \ltrim(\str_replace($root, '', $path), '/'),
                    'line' => $chunk['line'],
                    'score' => \round($score, 3),
                    'text' => self::snippet($chunk['text'], $terms),
                ];
            }
        }

        \usort($results, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $results = \array_slice($results, 0, \max(1, $limit));

        if ($results === []) {
            return ['ok' => true, 'output' => 'nothing in the working folder matches ' . \implode(' ', $terms), 'results' => []];
        }

        $lines = [];

        foreach ($results as $result) {
            $lines[] = '• ' . $result['path'] . ' around line ' . $result['line'] . "\n" . $result['text'];
        }

        return [
            'ok' => true,
            'output' => \implode("\n\n", $lines)
                . "\n\n(keyword search over " . \number_format($scanned) . " bytes"
                . ($this->embeddingModel() === null
                    ? '; not semantic search — no embedding model is installed'
                    : '; semantic search is available once the folder has been indexed') . ')',
            'results' => $results,
        ];
    }

    /** @return array{files: int, bytes: int, readable: list<string>} */
    public function stats(): array
    {
        $root = $this->settings->get('workspace');

        if (!\is_string($root) || !\is_dir($root)) {
            return ['files' => 0, 'bytes' => 0, 'readable' => []];
        }

        $files = 0;
        $bytes = 0;
        $kinds = [];

        foreach ($this->files($root) as $path) {
            $size = @\filesize($path);

            if ($size === false) {
                continue;
            }

            $files++;
            $bytes += (int) $size;
            $extension = \strtolower(\pathinfo($path, \PATHINFO_EXTENSION));

            if ($extension !== '') {
                $kinds[$extension] = true;
            }
        }

        $kinds = \array_keys($kinds);
        \sort($kinds);

        return ['files' => $files, 'bytes' => $bytes, 'readable' => $kinds];
    }

    /** @return list<string> */
    public function files(string $root): array
    {
        $found = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                if (\in_array($item->getFilename(), self::SKIP, true)) {
                    $iterator->next();
                }

                continue;
            }

            if (\count($found) >= self::MAX_FILES) {
                break;
            }

            $extension = \strtolower($item->getExtension());

            if (!\in_array($extension, self::TEXT, true)) {
                continue;
            }

            $found[] = $item->getPathname();
        }

        \sort($found);

        return $found;
    }

    /**
     * @return list<array{line: int, text: string}>
     */
    private function chunks(string $path): array
    {
        $key = $path . ':' . (string) @\filemtime($path) . ':' . (string) @\filesize($path);

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $handle = @\fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $chunks = [];
        $buffer = '';
        $line = 1;
        $chunkLine = 1;

        while (($row = \fgets($handle)) !== false) {
            $buffer .= $row;

            if (\strlen($buffer) >= self::CHUNK) {
                $chunks[] = ['line' => $chunkLine, 'text' => $buffer];
                $buffer = \substr($buffer, -self::OVERLAP);
                $chunkLine = $line;
            }

            $line++;
        }

        \fclose($handle);

        if (\trim($buffer) !== '') {
            $chunks[] = ['line' => $chunkLine, 'text' => $buffer];
        }

        return $this->cache[$key] = $chunks;
    }

    /** @return list<string> */
    private static function terms(string $query): array
    {
        $parts = \preg_split('/[^\p{L}\p{N}_]+/u', \mb_strtolower($query)) ?: [];
        $terms = [];

        foreach ($parts as $part) {
            if (\mb_strlen($part) >= 2 && !\in_array($part, $terms, true)) {
                $terms[] = $part;
            }
        }

        return $terms;
    }

    /** @param list<string> $terms */
    private static function score(string $text, array $terms): float
    {
        $haystack = \mb_strtolower($text);
        $score = 0.0;
        $matched = 0;

        foreach ($terms as $term) {
            $count = \substr_count($haystack, $term);

            if ($count > 0) {
                $matched++;
                $score += 1.0 + \log(1.0 + $count);
            }
        }

        if ($matched === 0) {
            return 0.0;
        }

        // Words next to each other are worth much more than the same words apart.
        if ($matched > 1 && \substr_count($haystack, \implode(' ', $terms)) > 0) {
            $score *= 2.5;
        }

        return $score * ($matched / \count($terms));
    }

    /**
     * @param list<string> $terms
     */
    private static function snippet(string $text, array $terms): string
    {
        $lower = \mb_strtolower($text);
        $at = false;

        foreach ($terms as $term) {
            $position = \mb_strpos($lower, $term);

            if ($position !== false && ($at === false || $position < $at)) {
                $at = $position;
            }
        }

        if ($at === false) {
            $at = 0;
        }

        $start = \max(0, $at - 240);
        $snippet = \trim(\mb_substr($text, $start, 700));

        return ($start > 0 ? '… ' : '') . $snippet;
    }
}
