<?php

declare(strict_types=1);

namespace App;

/**
 * Skills: markdown written for the model, loaded on demand.
 *
 * The shape is the one a small model can actually use well — names and one-line
 * descriptions in the prompt, the full text behind a tool. Loading every skill
 * into an 8k context would spend the context on instructions for work nobody
 * asked for, so `read_skill` exists instead.
 *
 * A skill is a markdown file in `~/.config/quiesce/skills`, either `name.md` or
 * `name/SKILL.md`. Optional frontmatter gives the description:
 *
 *     ---
 *     description: how to write release notes in this repository
 *     ---
 */
final class Skills
{
    public const int MAX_SKILL = 24_000;

    public const int MAX_LISTED = 60;

    public function __construct(private readonly string $directory) {}

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * The catalogue: names and descriptions only. This is what goes in the prompt.
     *
     * @return list<array{name: string, description: string, path: string}>
     */
    public function catalogue(): array
    {
        $found = [];

        foreach ($this->files() as $path) {
            $name = $this->nameOf($path);
            $found[$name] = [
                'name' => $name,
                'description' => $this->describe($path),
                'path' => $path,
            ];
        }

        \ksort($found);

        return \array_slice(\array_values($found), 0, self::MAX_LISTED);
    }

    /** @return array{ok: bool, output: string} */
    public function read(string $name): array
    {
        $name = \trim($name);

        foreach ($this->catalogue() as $skill) {
            if (\strcasecmp($skill['name'], $name) === 0) {
                return [
                    'ok' => true,
                    'output' => \substr((string) @\file_get_contents($skill['path']), 0, self::MAX_SKILL),
                ];
            }
        }

        $names = \array_column($this->catalogue(), 'name');

        return [
            'ok' => false,
            'output' => $names === []
                ? 'there are no skills in this application yet'
                : 'no skill called "' . $name . '". Available: ' . \implode(', ', $names),
        ];
    }

    /** The tool description lists what exists, so the model knows what to ask for. */
    public function toolDescription(): string
    {
        $catalogue = $this->catalogue();

        if ($catalogue === []) {
            return 'Load a skill: instructions the reader has written for how to do a particular kind of work '
                . 'here. There are none yet.';
        }

        $lines = ['Load a skill: instructions the reader has written for how to do work like this. Available:'];

        foreach ($catalogue as $skill) {
            $lines[] = '- ' . $skill['name'] . ': ' . $skill['description'];
        }

        return \implode("\n", $lines);
    }

    /** @return list<string> */
    private function files(): array
    {
        if (!\is_dir($this->directory)) {
            return [];
        }

        $paths = [];

        foreach (\glob($this->directory . '/*.md') ?: [] as $file) {
            $paths[] = $file;
        }

        foreach (\glob($this->directory . '/*/SKILL.md') ?: [] as $file) {
            $paths[] = $file;
        }

        \sort($paths);

        return $paths;
    }

    private function nameOf(string $path): string
    {
        return \basename($path) === 'SKILL.md'
            ? \basename(\dirname($path))
            : \basename($path, '.md');
    }

    private function describe(string $path): string
    {
        $head = \substr((string) @\file_get_contents($path), 0, 2_000);

        if (\preg_match('/^---\s*\n(.*?)\n---/s', $head, $m) === 1
            && \preg_match('/^description:\s*(.+)$/mi', $m[1], $d) === 1) {
            return \trim($d[1], " \t\"'");
        }

        // Otherwise: the first line that is not a heading, a rule, or blank.
        foreach (\explode("\n", $head) as $line) {
            $line = \trim($line);

            if ($line === '' || \str_starts_with($line, '#') || \str_starts_with($line, '---')) {
                continue;
            }

            return \substr($line, 0, 120);
        }

        return 'no description';
    }
}
