<?php

declare(strict_types=1);

namespace App;

/**
 * The tools the model is allowed to use.
 *
 * Two families, with different rules:
 *
 *   - **Files.** Confined to one folder the reader chooses. Every path is
 *     resolved with `realpath()` and then checked to be inside that folder, so
 *     `../`, absolute paths and symlinks that point out of it are all refused,
 *     not sanitised. A tool that can write anywhere is not a tool, it is a
 *     liability.
 *   - **The web.** Deliberately leaves this machine, so it is switched
 *     explicitly, every call is reported with its URL, and results are marked
 *     as having left the device. Loopback and private addresses are refused, so
 *     a fetched page cannot be turned into a probe of the reader's own network.
 *
 * Search has no key and no account, which limits it honestly: a SearXNG
 * instance if the reader gives one, otherwise DuckDuckGo's instant-answer API
 * and Wikipedia. Fetching a page the reader names always works.
 */
final class Tools
{
    public const int MAX_READ = 65_536;

    public const int MAX_WRITE = 262_144;

    public const int MAX_LIST = 200;

    public const int MAX_FETCH = 262_144;

    public const int MAX_RESULTS = 6;

    public const string USER_AGENT = 'Quiesce/0.1 (local desktop app; +https://example.invalid)';

    private bool $allowDelegate = false;

    /** A subagent is offered everything except the ability to delegate again. */
    public function allowDelegate(bool $allow): void
    {
        $this->allowDelegate = $allow;
    }

    public function __construct(
        private readonly Settings $settings,
        private readonly ?Shell $shell = null,
        private readonly ?Skills $skills = null,
        private readonly ?Documents $documents = null,
        private readonly ?Mcp $mcp = null,
        private readonly ?Vision $vision = null,
        private readonly ?Checkpoints $checkpoints = null,
    ) {}

    public function shell(): ?Shell
    {
        return $this->shell;
    }

    /**
     * Put the newest checkpointed change back.
     *
     * Writes funnel through this class, which is why the net can be here: one place to
     * stand, in front of every file the agent changes, rather than a hook per tool. See
     * `App\Checkpoints` for what is held and for how long.
     *
     * @return array{ok: bool, output: string}
     */
    public function undoLast(): array
    {
        return $this->checkpoints?->undoLast() ?? ['ok' => false, 'output' => 'this build has no checkpoints'];
    }

    /** How many changes are held, so the window can say. */
    public function checkpoints(): int
    {
        return $this->checkpoints?->count() ?? 0;
    }

    /* ------------------------------------------------------------------ *
     *  The folder the tools live in
     * ------------------------------------------------------------------ */

    public function workspace(): ?string
    {
        $path = $this->settings->get('workspace');

        if (!\is_string($path) || $path === '') {
            return null;
        }

        $real = \realpath($path);

        return $real !== false && \is_dir($real) ? $real : null;
    }

    /** @return array{ok: bool, workspace?: string, error?: string} */
    public function setWorkspace(?string $path): array
    {
        if ($path === null || \trim($path) === '') {
            $this->settings->set('workspace', '');
            $this->settings->save();

            return ['ok' => true, 'workspace' => ''];
        }

        $real = \realpath($path);

        if ($real === false || !\is_dir($real)) {
            return ['ok' => false, 'error' => 'not a folder: ' . $path];
        }

        if (!\is_writable($real)) {
            return ['ok' => false, 'error' => 'that folder is not writable: ' . $real];
        }

        $this->settings->set('workspace', $real);
        $this->settings->save();

        return ['ok' => true, 'workspace' => $real];
    }

    public function webEnabled(): bool
    {
        return (bool) $this->settings->get('web', true);
    }

    public function setWeb(bool $enabled): void
    {
        $this->settings->set('web', $enabled);
        $this->settings->save();
    }

    public function searchUrl(): ?string
    {
        $url = $this->settings->get('search_url');

        return \is_string($url) && $url !== '' ? \rtrim($url, '/') : null;
    }

    /** @return array{ok: bool, url?: string, error?: string} */
    public function setSearchUrl(?string $url): array
    {
        if ($url === null || \trim($url) === '') {
            $this->settings->set('search_url', '');
            $this->settings->save();

            return ['ok' => true, 'url' => ''];
        }

        $parts = \parse_url($url);

        if (!\is_array($parts) || !\in_array(\strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return ['ok' => false, 'error' => 'that is not an http(s) address'];
        }

        $this->settings->set('search_url', \rtrim($url, '/'));
        $this->settings->save();

        return ['ok' => true, 'url' => \rtrim($url, '/')];
    }

    /* ------------------------------------------------------------------ *
     *  What the model is offered
     * ------------------------------------------------------------------ */

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        $workspace = $this->workspace() ?? '(no folder chosen yet)';

        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_files',
                    'description' => 'List the files and folders inside the working folder (' . $workspace . '). '
                        . 'Paths are relative to that folder. Use "." for the top level.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string', 'description' => 'folder to list, relative to the working folder'],
                        ],
                        'required' => ['path'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'read_file',
                    'description' => 'Read a text file inside the working folder. Returns at most '
                        . self::MAX_READ . ' bytes. Only files inside the working folder can be read.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string', 'description' => 'file path relative to the working folder'],
                        ],
                        'required' => ['path'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'write_file',
                    'description' => 'Write a text file inside the working folder, replacing it if it exists and '
                        . 'creating parent folders as needed. This is the only way to change anything on disk.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string', 'description' => 'file path relative to the working folder'],
                            'content' => ['type' => 'string', 'description' => 'the whole new contents of the file'],
                        ],
                        'required' => ['path', 'content'],
                    ],
                ],
            ],
        ];

        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'edit_file',
                'description' => 'Change part of a file in the working folder by replacing an exact piece of its text. '
                    . 'Prefer this to write_file for edits: the text to find must appear exactly once, so a mistake '
                    . 'is refused rather than written over the wrong place. Returns a short diff of what changed.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'file path relative to the working folder'],
                        'find' => ['type' => 'string', 'description' => 'the exact text to replace, including its indentation'],
                        'replace' => ['type' => 'string', 'description' => 'the text to put in its place; empty deletes it'],
                        'all' => ['type' => 'boolean', 'description' => 'replace every occurrence instead of refusing when there is more than one'],
                    ],
                    'required' => ['path', 'find', 'replace'],
                ],
            ],
        ];

        if ($this->skills !== null && $this->skills->catalogue() !== []) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'read_skill',
                    'description' => $this->skills->toolDescription(),
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'the skill to load'],
                        ],
                        'required' => ['name'],
                    ],
                ],
            ];
        }

        if ($this->documents !== null && $this->workspace() !== null) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'search_documents',
                    'description' => 'Find text in the files of the working folder by keyword. Returns the files, '
                        . 'approximate line numbers and the passages that matched. Keyword search, not semantic: '
                        . 'search for the words likely to be in the text, not for a question.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'words to look for'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ];
        }

        if ($this->allowDelegate) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'delegate',
                    'description' => 'Hand a self-contained subtask to a fresh agent with its own clean context, and get '
                        . 'back only its answer. Use it for a job that would otherwise fill your own context with '
                        . 'material you do not need to keep — reading several files to answer one question, for '
                        . 'instance. It has the same tools you do (files, commands with the reader\'s approval, '
                        . 'search, web) but no memory of this conversation, so say everything it needs.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'task' => ['type' => 'string', 'description' => 'the whole instruction for the subagent'],
                        ],
                        'required' => ['task'],
                    ],
                ],
            ];
        }

        if ($this->vision !== null && $this->vision->chosen() !== null) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'look_at_image',
                    'description' => 'Look at an image inside the working folder with the local vision model ('
                        . (string) $this->vision->chosen() . ') and report what is in it.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string', 'description' => 'image path relative to the working folder'],
                            'question' => ['type' => 'string', 'description' => 'what to ask about it'],
                        ],
                        'required' => ['path'],
                    ],
                ],
            ];
        }

        if ($this->shell !== null && $this->shell->enabled()) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'run_command',
                    'description' => 'Run a command in the working folder (' . $workspace . '). Commands that only '
                        . 'read run immediately; commands that could change something wait for the reader to allow '
                        . 'them; a few are refused outright. Output is capped and there is a timeout.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'command' => ['type' => 'string', 'description' => 'the command line to run'],
                            'timeout' => ['type' => 'integer', 'description' => 'seconds to allow, up to ' . Shell::MAX_TIMEOUT],
                        ],
                        'required' => ['command'],
                    ],
                ],
            ];
        }

        if ($this->webEnabled()) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'web_search',
                    'description' => 'Search the web for a few results. This sends the query off this machine. '
                        . ($this->searchUrl() !== null
                            ? 'It uses the SearXNG instance the reader configured.'
                            : 'No search instance is configured, so this falls back to DuckDuckGo instant answers and Wikipedia.'),
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'what to search for'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ];
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'web_fetch',
                    'description' => 'Fetch one http(s) page and return its readable text. This sends the request '
                        . 'off this machine. Local and private addresses are refused.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'url' => ['type' => 'string', 'description' => 'the full http(s) address to read'],
                        ],
                        'required' => ['url'],
                    ],
                ],
            ];
        }

        if ($this->mcp !== null) {
            foreach ($this->mcp->toolDefinitions() as $definition) {
                $tools[] = $definition;
            }
        }

        return $tools;
    }

    /** A short line for the window: what was called, with the part that matters. */
    public function describe(string $name, array $arguments): string
    {
        return match ($name) {
            'list_files' => 'list_files ' . (string) ($arguments['path'] ?? '.'),
            'read_file' => 'read_file ' . (string) ($arguments['path'] ?? ''),
            'write_file' => 'write_file ' . (string) ($arguments['path'] ?? '') . ' ('
                . \strlen((string) ($arguments['content'] ?? '')) . ' bytes)',
            'edit_file' => 'edit_file ' . (string) ($arguments['path'] ?? '') . ' ('
                . \strlen((string) ($arguments['find'] ?? '')) . ' → ' . \strlen((string) ($arguments['replace'] ?? '')) . ' bytes)',
            'run_command' => 'run_command ' . (string) ($arguments['command'] ?? ''),
            'read_skill' => 'read_skill ' . (string) ($arguments['name'] ?? ''),
            'look_at_image' => 'look_at_image ' . (string) ($arguments['path'] ?? ''),
            'delegate' => 'delegate "' . \substr((string) ($arguments['task'] ?? ''), 0, 100) . '"',
            'search_documents' => 'search_documents "' . (string) ($arguments['query'] ?? '') . '"',
            'web_search' => 'web_search "' . (string) ($arguments['query'] ?? '') . '"',
            'web_fetch' => 'web_fetch ' . (string) ($arguments['url'] ?? ''),
            default => $name,
        };
    }

    /**
     * Web tools leave the machine; MCP tools run in another program the reader
     * installed, which this app cannot vouch for. Both are marked in the step
     * list so the reader can see what ran outside the model's own sandbox.
     */
    public function leavesTheMachine(string $name): bool
    {
        return $name === 'web_search'
            || $name === 'web_fetch'
            || \str_starts_with($name, 'mcp_');
    }

    /**
     * Run one tool call.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{ok: bool, output: string}
     */
    public function run(string $name, array $arguments): array
    {
        return match ($name) {
            'list_files' => $this->listFiles((string) ($arguments['path'] ?? '.')),
            'read_file' => $this->readFile((string) ($arguments['path'] ?? '')),
            'write_file' => $this->writeFile((string) ($arguments['path'] ?? ''), (string) ($arguments['content'] ?? '')),
            'edit_file' => $this->editFile(
                (string) ($arguments['path'] ?? ''),
                (string) ($arguments['find'] ?? ''),
                (string) ($arguments['replace'] ?? ''),
                ($arguments['all'] ?? false) === true,
            ),
            // A command is not run from here: the agent starts it and steps it,
            // so that a minute-long build does not freeze the window.
            'run_command' => ['ok' => false, 'output' => 'commands are started by the agent, not called directly'],
            'read_skill' => $this->skills !== null
                ? $this->skills->read((string) ($arguments['name'] ?? ''))
                : ['ok' => false, 'output' => 'this application has no skills folder'],
            'delegate' => ['ok' => false, 'output' => 'delegation is driven by the agent, not called directly'],
            'look_at_image' => $this->vision !== null
                ? $this->vision->describe((string) ($arguments['path'] ?? ''), (string) ($arguments['question'] ?? ''))
                : ['ok' => false, 'output' => 'no vision model is available'],
            'search_documents' => $this->documents !== null
                ? $this->documents->search((string) ($arguments['query'] ?? ''))
                : ['ok' => false, 'output' => 'document search is not available'],
            'web_search' => $this->webEnabled()
                ? $this->search((string) ($arguments['query'] ?? ''))
                : ['ok' => false, 'output' => 'web tools are switched off'],
            'web_fetch' => $this->webEnabled()
                ? $this->fetch((string) ($arguments['url'] ?? ''))
                : ['ok' => false, 'output' => 'web tools are switched off'],
            default => ['ok' => false, 'output' => 'there is no tool called ' . $name],
        };
    }

    /* ------------------------------------------------------------------ *
     *  Files
     * ------------------------------------------------------------------ */

    /** @return array{ok: bool, output: string} */
    private function listFiles(string $relative): array
    {
        $root = $this->workspace();

        if ($root === null) {
            return ['ok' => false, 'output' => 'no working folder has been chosen yet'];
        }

        $target = $this->resolveExisting($root, $relative);

        if ($target === null) {
            return ['ok' => false, 'output' => 'refused: "' . $relative . '" is not a readable path inside the working folder'];
        }

        if (!\is_dir($target)) {
            return ['ok' => false, 'output' => $relative . ' is a file, not a folder'];
        }

        $entries = \scandir($target);

        if ($entries === false) {
            return ['ok' => false, 'output' => 'could not read that folder'];
        }

        $lines = [];
        $count = 0;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (++$count > self::MAX_LIST) {
                $lines[] = '… (more than ' . self::MAX_LIST . ' entries, list a subfolder to see the rest)';

                break;
            }

            $path = $target . '/' . $entry;
            $relativePath = \ltrim(\str_replace($root, '', $path), '/');

            if (\is_dir($path)) {
                // Say how big a folder is, not just that it exists: "read the
                // largest file" is unanswerable otherwise, and both models tested
                // here gave up and read a small file at the top level instead.
                $inside = $this->weigh($path);
                $lines[] = $relativePath . '/  ' . $inside['files'] . ' file'
                    . ($inside['files'] === 1 ? '' : 's') . '  ' . self::bytes($inside['bytes']);

                continue;
            }

            $lines[] = $relativePath . '  ' . self::bytes(\filesize($path) ?: 0);
        }

        if ($lines === []) {
            return ['ok' => true, 'output' => '(the folder is empty)'];
        }

        \sort($lines);

        return ['ok' => true, 'output' => \implode("\n", $lines)];
    }

    /**
     * What is actually on disk at a path in the working folder.
     *
     * The agent's own tool for checking its own work: after a write, the file is
     * read back and its size compared with what was sent. "I wrote it" and "it is
     * there, at that size" are different claims, and only one of them is worth
     * reporting.
     *
     * @return array{ok: bool, bytes?: int, modified?: int, error?: string}
     */
    public function stat(string $relative): array
    {
        $root = $this->workspace();

        if ($root === null) {
            return ['ok' => false, 'error' => 'no working folder has been chosen yet'];
        }

        $target = $this->resolveExisting($root, $relative);

        if ($target === null || !\is_file($target)) {
            return ['ok' => false, 'error' => 'there is no file at ' . $relative];
        }

        return [
            'ok' => true,
            'bytes' => (int) (@\filesize($target) ?: 0),
            'modified' => (int) (@\filemtime($target) ?: 0),
        ];
    }

    /** How much is inside a folder, to the depth that matters for choosing. */
    private function weigh(string $path, int $depth = 0): array
    {
        if ($depth > 3) {
            return ['files' => 0, 'bytes' => 0];
        }

        $files = 0;
        $bytes = 0;

        foreach (@\scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.git' || $entry === 'node_modules') {
                continue;
            }

            $child = $path . '/' . $entry;

            if (\is_dir($child)) {
                $inside = $this->weigh($child, $depth + 1);
                $files += $inside['files'];
                $bytes += $inside['bytes'];

                continue;
            }

            $files++;
            $bytes += (int) (@\filesize($child) ?: 0);
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    /** @return array{ok: bool, output: string} */
    private function readFile(string $relative): array
    {
        $root = $this->workspace();

        if ($root === null) {
            return ['ok' => false, 'output' => 'no working folder has been chosen yet'];
        }

        $target = $this->resolveExisting($root, $relative);

        if ($target === null || !\is_file($target)) {
            return ['ok' => false, 'output' => 'refused: "' . $relative . '" is not a file inside the working folder'];
        }

        $size = \filesize($target) ?: 0;
        $handle = @\fopen($target, 'rb');

        if ($handle === false) {
            return ['ok' => false, 'output' => 'could not open that file'];
        }

        $contents = (string) \fread($handle, self::MAX_READ);
        \fclose($handle);

        $truncated = $size > self::MAX_READ
            ? "\n\n… truncated at " . self::bytes(self::MAX_READ) . ' of ' . self::bytes($size)
            : '';

        if (\str_contains($contents, "\0")) {
            return ['ok' => true, 'output' => '(binary file, ' . self::bytes($size) . ' — not shown)'];
        }

        return ['ok' => true, 'output' => $contents . $truncated];
    }

    /** @return array{ok: bool, output: string} */
    private function writeFile(string $relative, string $content): array
    {
        $root = $this->workspace();

        if ($root === null) {
            return ['ok' => false, 'output' => 'no working folder has been chosen yet'];
        }

        if (\strlen($content) > self::MAX_WRITE) {
            return ['ok' => false, 'output' => 'refused: that is larger than ' . self::bytes(self::MAX_WRITE)];
        }

        $target = $this->resolveNew($root, $relative);

        if ($target === null) {
            return ['ok' => false, 'output' => 'refused: "' . $relative . '" is not a path inside the working folder'];
        }

        $directory = \dirname($target);

        if (!\is_dir($directory) && !@\mkdir($directory, 0o755, true)) {
            return ['ok' => false, 'output' => 'could not create ' . $directory];
        }

        // mkdir may have followed a symlink out of the folder: check again.
        if ($this->resolveNew($root, $relative) === null) {
            return ['ok' => false, 'output' => 'refused: that path leaves the working folder'];
        }

        // Aside first: what was there before this line is the only copy of it that
        // will exist afterwards.
        $checkpoint = $this->checkpoints?->remember($root, $target, 'write_file');

        $written = @\file_put_contents($target, $content);

        if ($written === false) {
            return ['ok' => false, 'output' => 'could not write that file'];
        }

        $output = 'wrote ' . self::bytes($written) . ' to ' . \ltrim(\str_replace($root, '', $target), '/');

        if (\is_array($checkpoint) && ($checkpoint['ok'] ?? false) !== true) {
            $output .= "\n(" . (string) ($checkpoint['note'] ?? 'not checkpointed') . ')';
        }

        return ['ok' => true, 'output' => $output];
    }

    /**
     * Replace an exact piece of a file, and say what changed.
     *
     * The whole point is what it *refuses*: text that does not appear, or appears
     * more than once, is not guessed at. A model that means to change one function
     * and quietly changes three is worse than one that fails and is told why.
     *
     * @return array{ok: bool, output: string, diff?: string}
     */
    private function editFile(string $relative, string $find, string $replace, bool $all): array
    {
        $root = $this->workspace();

        if ($root === null) {
            return ['ok' => false, 'output' => 'no working folder has been chosen yet'];
        }

        if ($find === '') {
            return ['ok' => false, 'output' => 'nothing to find: pass the exact text to replace'];
        }

        $target = $this->resolveExisting($root, $relative);

        if ($target === null || !\is_file($target)) {
            return ['ok' => false, 'output' => 'refused: "' . $relative . '" is not a file inside the working folder'];
        }

        $size = @\filesize($target) ?: 0;

        if ($size > self::MAX_WRITE) {
            return ['ok' => false, 'output' => 'that file is larger than ' . self::bytes(self::MAX_WRITE) . ' — editing it in one piece is not safe'];
        }

        $old = @\file_get_contents($target);

        if (!\is_string($old)) {
            return ['ok' => false, 'output' => 'could not read that file'];
        }

        $count = \substr_count($old, $find);

        if ($count === 0) {
            return ['ok' => false, 'output' => 'that text is not in ' . $relative . ' — read the file and copy the text exactly, indentation included'];
        }

        if ($count > 1 && !$all) {
            return ['ok' => false, 'output' => 'that text appears ' . $count . ' times in ' . $relative
                . ' — include more of the surrounding text so it is unique, or pass "all": true to change every one'];
        }

        $offset = (int) \strpos($old, $find);

        $new = $all
            ? \str_replace($find, $replace, $old)
            : \substr_replace($old, $replace, $offset, \strlen($find));

        $checkpoint = $this->checkpoints?->remember($root, $target, 'edit_file');

        if (@\file_put_contents($target, $new) === false) {
            return ['ok' => false, 'output' => 'could not write ' . $relative];
        }

        $diff = self::diffAround($old, $find, $replace, $offset, $all ? $count : 1);

        $output = 'edited ' . \ltrim(\str_replace($root, '', $target), '/') . ' ('
            . ($all ? $count . ' places' : 'one place') . ")\n\n" . $diff;

        if (\is_array($checkpoint) && ($checkpoint['ok'] ?? false) !== true) {
            $output .= "\n(" . (string) ($checkpoint['note'] ?? 'not checkpointed') . ')';
        }

        return [
            'ok' => true,
            'output' => $output,
            'diff' => $diff,
        ];
    }

    /**
     * A short, honest view of the change: the lines that went, the lines that
     * arrived, and a little context. Not a full unified diff — a diff of what this
     * one edit did, which is what a reader wants to see.
     */
    private static function diffAround(string $old, string $find, string $replace, int $offset, int $places): string
    {
        $line = \substr_count(\substr($old, 0, $offset), "\n") + 1;
        $before = \explode("\n", $old);

        $context = 2;
        $lines = ['@@ around line ' . $line . ($places > 1 ? ' (' . $places . ' places)' : '') . ' @@'];

        for ($i = \max(0, $line - 1 - $context); $i < $line - 1; $i++) {
            $lines[] = '  ' . \rtrim($before[$i] ?? '');
        }

        foreach (\explode("\n", \rtrim($find, "\n")) as $gone) {
            $lines[] = '- ' . $gone;
        }

        foreach (\explode("\n", \rtrim($replace, "\n")) as $arrived) {
            $lines[] = '+ ' . $arrived;
        }

        $after = $line + \substr_count($find, "\n");

        for ($i = $after; $i < $after + $context; $i++) {
            if (isset($before[$i])) {
                $lines[] = '  ' . \rtrim($before[$i]);
            }
        }

        return \implode("\n", $lines);
    }

    /**
     * Resolve a path that must already exist, and must be inside the root.
     * `realpath()` is the check: it follows symlinks, so a link pointing out of
     * the folder resolves out of the folder and is refused.
     */
    private function resolveExisting(string $root, string $relative): ?string
    {
        if (!self::looksLikeAPath($relative)) {
            return null;
        }

        $candidate = \realpath($root . '/' . $relative);

        if ($candidate === false) {
            return null;
        }

        return self::inside($root, $candidate) ? $candidate : null;
    }

    /**
     * Resolve a path that may not exist yet (a write target).
     *
     * The nearest existing ancestor is resolved with `realpath()` and checked to
     * be inside the folder; the rest of the path is new, so it cannot be a
     * symlink pointing anywhere. Rebuilding from the verified ancestor (rather
     * than trusting the written path) is what keeps `sub/dir/new.txt` working
     * while `symlink/invaded.txt` still does not.
     */
    private function resolveNew(string $root, string $relative): ?string
    {
        if (!self::looksLikeAPath($relative)) {
            return null;
        }

        $candidate = $root . '/' . $relative;

        if (\file_exists($candidate)) {
            $real = \realpath($candidate);

            return $real !== false && self::inside($root, $real) ? $real : null;
        }

        $directory = \dirname($candidate);

        while (!\is_dir($directory)) {
            $parent = \dirname($directory);

            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }

        $resolved = \realpath($directory);

        if ($resolved === false || !self::inside($root, $resolved)) {
            return null;
        }

        return $resolved . \substr($candidate, \strlen($directory));
    }

    private static function looksLikeAPath(string $relative): bool
    {
        if ($relative === '' || \str_contains($relative, "\0")) {
            return false;
        }

        // Absolute paths and home shortcuts are never accepted, however they are
        // spelled; the model works relative to the chosen folder or not at all.
        if (\str_starts_with($relative, '/') || \str_starts_with($relative, '~')) {
            return false;
        }

        if (\preg_match('#^[A-Za-z]:[\\\\/]#', $relative) === 1) {
            return false;
        }

        return true;
    }

    private static function inside(string $root, string $candidate): bool
    {
        return $candidate === $root || \str_starts_with($candidate, \rtrim($root, '/') . '/');
    }

    private static function bytes(int $size): string
    {
        return $size >= 1_048_576
            ? \number_format($size / 1_048_576, 1) . ' MB'
            : ($size >= 1_024 ? \number_format($size / 1_024, 1) . ' KB' : $size . ' B');
    }

    /* ------------------------------------------------------------------ *
     *  The web
     * ------------------------------------------------------------------ */

    /** @return array{ok: bool, output: string} */
    private function search(string $query): array
    {
        $query = \trim($query);

        if ($query === '') {
            return ['ok' => false, 'output' => 'no query given'];
        }

        $instance = $this->searchUrl();

        if ($instance !== null) {
            $result = $this->searchSearxng($instance, $query);

            if ($result !== null) {
                return ['ok' => true, 'output' => $result];
            }

            // Fall through rather than fail: a dead instance should not leave
            // the model with nothing at all.
        }

        $lines = [];

        $instant = $this->searchInstantAnswer($query);

        if ($instant !== null) {
            $lines[] = $instant;
        }

        $wikipedia = $this->searchWikipedia($query);

        if ($wikipedia !== null) {
            $lines[] = $wikipedia;
        }

        if ($lines === []) {
            return ['ok' => false, 'output' => 'nothing came back. (Key-free search is limited: '
                . 'set a SearXNG address in settings for real web search.)'];
        }

        $note = $instance === null
            ? "\n\n(no search instance configured; these came from DuckDuckGo instant answers and Wikipedia)"
            : '';

        return ['ok' => true, 'output' => \implode("\n\n", $lines) . $note];
    }

    private function searchSearxng(string $instance, string $query): ?string
    {
        $url = $instance . '/search?q=' . \urlencode($query) . '&format=json';

        $body = $this->request($url);

        if ($body === null) {
            return null;
        }

        $decoded = \json_decode($body, true);

        if (!\is_array($decoded) || !\is_array($decoded['results'] ?? null)) {
            return null;
        }

        $lines = [];

        foreach (\array_slice($decoded['results'], 0, self::MAX_RESULTS) as $result) {
            if (!\is_array($result)) {
                continue;
            }

            $lines[] = '• ' . (string) ($result['title'] ?? '')
                . "\n  " . (string) ($result['url'] ?? '')
                . "\n  " . \trim((string) ($result['content'] ?? ''));
        }

        return $lines === [] ? null : \implode("\n\n", $lines);
    }

    private function searchInstantAnswer(string $query): ?string
    {
        $body = $this->request('https://api.duckduckgo.com/?q=' . \urlencode($query) . '&format=json&no_html=1&no_redirect=1');

        if ($body === null) {
            return null;
        }

        $decoded = \json_decode($body, true);

        if (!\is_array($decoded)) {
            return null;
        }

        $lines = [];

        if (($decoded['AbstractText'] ?? '') !== '') {
            $lines[] = '• ' . (string) $decoded['AbstractText']
                . (($decoded['AbstractURL'] ?? '') !== '' ? "\n  " . (string) $decoded['AbstractURL'] : '');
        }

        foreach (\array_slice(\is_array($decoded['RelatedTopics'] ?? null) ? $decoded['RelatedTopics'] : [], 0, self::MAX_RESULTS) as $topic) {
            if (\is_array($topic) && isset($topic['Text'])) {
                $lines[] = '• ' . (string) $topic['Text']
                    . (isset($topic['FirstURL']) ? "\n  " . (string) $topic['FirstURL'] : '');
            }
        }

        return $lines === [] ? null : \implode("\n\n", \array_slice($lines, 0, self::MAX_RESULTS));
    }

    private function searchWikipedia(string $query): ?string
    {
        $body = $this->request('https://en.wikipedia.org/w/api.php?action=query&list=search&srsearch='
            . \urlencode($query) . '&format=json&srlimit=' . self::MAX_RESULTS);

        if ($body === null) {
            return null;
        }

        $decoded = \json_decode($body, true);
        $hits = $decoded['query']['search'] ?? null;

        if (!\is_array($hits) || $hits === []) {
            return null;
        }

        $lines = ['From Wikipedia:'];

        foreach ($hits as $hit) {
            if (!\is_array($hit)) {
                continue;
            }

            $title = (string) ($hit['title'] ?? '');
            $snippet = \trim(\html_entity_decode(\strip_tags((string) ($hit['snippet'] ?? ''))));

            $lines[] = '• ' . $title . "\n  https://en.wikipedia.org/wiki/" . \rawurlencode(\str_replace(' ', '_', $title))
                . "\n  " . $snippet;
        }

        return \implode("\n\n", $lines);
    }

    /** @return array{ok: bool, output: string} */
    private function fetch(string $url): array
    {
        $url = \trim($url);

        if ($url === '') {
            return ['ok' => false, 'output' => 'no url given'];
        }

        $refusal = $this->addressRefusal($url);

        if ($refusal !== null) {
            return ['ok' => false, 'output' => $refusal];
        }

        $body = $this->request($url, 20, 3);

        if ($body === null) {
            return ['ok' => false, 'output' => 'could not fetch ' . $url];
        }

        $text = self::htmlToText($body);

        return [
            'ok' => true,
            'output' => $text === '' ? '(that page had no readable text)' : $text,
        ];
    }

    /**
     * Refuse anything that is not a public http(s) address, so a page the model
     * chose cannot become a probe of the reader's own machine or network.
     */
    private function addressRefusal(string $url): ?string
    {
        $parts = \parse_url($url);

        if (!\is_array($parts)) {
            return 'that is not a url';
        }

        $scheme = \strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (!\in_array($scheme, ['http', 'https'], true)) {
            return 'only http and https can be fetched';
        }

        if ($host === '') {
            return 'that url has no host';
        }

        $addresses = \filter_var($host, \FILTER_VALIDATE_IP) !== false
            ? [$host]
            : (\gethostbynamel($host) ?: []);

        if ($addresses === []) {
            return 'that host does not resolve';
        }

        foreach ($addresses as $address) {
            if (\filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'refused: ' . $host . ' resolves to a private or local address';
            }
        }

        return null;
    }

    /** A capped GET. Returns null on any failure. */
    private function request(string $url, int $timeout = 20, int $redirects = 2): ?string
    {
        $context = \stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: " . self::USER_AGENT . "\r\nAccept: text/html, application/json;q=0.9, */*;q=0.5\r\n",
                'timeout' => $timeout,
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $handle = @\fopen($url, 'rb', false, $context);

        if ($handle === false) {
            return null;
        }

        $meta = \stream_get_meta_data($handle);
        $body = \stream_get_contents($handle, self::MAX_FETCH + 1);
        \fclose($handle);

        if ($body === false) {
            return null;
        }

        $status = 0;

        foreach ($meta['wrapper_data'] ?? [] as $header) {
            if (\preg_match('#^HTTP/\d\.\d\s+(\d{3})#', (string) $header, $m) === 1) {
                $status = (int) $m[1];
            } elseif (\preg_match('#^location:\s*(.+)$#i', (string) $header, $m) === 1) {
                $location = \trim($m[1]);

                if ($redirects > 0) {
                    $next = \str_starts_with($location, 'http') ? $location : self::absolute($url, $location);

                    if ($next !== null && $this->addressRefusal($next) === null) {
                        return $this->request($next, $timeout, $redirects - 1);
                    }
                }

                return null;
            }
        }

        if ($status >= 400) {
            return null;
        }

        return \substr($body, 0, self::MAX_FETCH);
    }

    private static function absolute(string $base, string $location): ?string
    {
        $parts = \parse_url($base);

        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (\str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return \str_starts_with($location, '/')
            ? $origin . $location
            : $origin . '/' . \ltrim(\dirname($parts['path'] ?? '/'), '/') . '/' . $location;
    }

    /** HTML in, readable text out. Not a parser: scripts and tags gone, entities decoded. */
    public static function htmlToText(string $html): string
    {
        $html = \preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html) ?? $html;
        $html = \preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $html) ?? $html;
        $html = \preg_replace('#<!--.*?-->#s', ' ', $html) ?? $html;
        $html = \preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\s*/?>#i', "\n", $html) ?? $html;
        $html = \preg_replace('#<li\b[^>]*>#i', "\n• ", $html) ?? $html;
        $html = \strip_tags($html);
        $html = \html_entity_decode($html, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $html = \preg_replace('/[ \t]+/', ' ', $html) ?? $html;
        $html = \preg_replace('/\n{3,}/', "\n\n", $html) ?? $html;

        return \trim($html);
    }
}
