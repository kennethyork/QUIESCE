<?php

declare(strict_types=1);

namespace App;

/**
 * Handing a file to a model.
 *
 * Two kinds, and the difference matters:
 *
 *  - **text** is inlined into the message, capped, with a note saying it was
 *    capped. The model reads it the way it reads anything else.
 *  - **images** are attached as image data, which only a vision model can use.
 *    Handing an image to a text-only model does not fail — the model answers
 *    *something*, confidently and wrongly — so this refuses instead and says
 *    which model to pick.
 *
 * Files come from the working folder, by the same `realpath()` rule the other
 * tools use, so the file picker cannot be used to post your home directory to a
 * model server.
 */
final class Attachments
{
    public const int MAX_TEXT = 24_000;

    public const int MAX_IMAGE = 8_388_608;

    /** @var list<string> */
    public const array IMAGES = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp'];

    /** @var list<string> */
    public const array TEXT = [
        'md', 'markdown', 'txt', 'text', 'rst', 'org', 'json', 'jsonl', 'csv', 'tsv',
        'yml', 'yaml', 'toml', 'ini', 'cfg', 'conf', 'env', 'sql', 'log',
        'php', 'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx', 'py', 'rb', 'go', 'rs', 'java',
        'c', 'h', 'cpp', 'hpp', 'cs', 'sh', 'bash', 'zsh', 'fish', 'css', 'scss', 'html',
        'htm', 'xml', 'svg', 'tex', 'lua', 'pl', 'r', 'kt', 'swift', 'vue', 'svelte',
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly ?Vision $vision = null,
    ) {}

    /**
     * The workspace-relative path, which is what the tools speak in — the file
     * picker hands back an absolute one, and `Vision` (rightly) refuses those.
     */
    public function relative(string $path): ?string
    {
        $root = $this->settings->get('workspace');
        $real = \realpath($path);

        if (!\is_string($root) || $real === false) {
            return null;
        }

        $root = (string) \realpath($root);

        if (!\str_starts_with($real, \rtrim($root, '/') . '/')) {
            return null;
        }

        return \ltrim(\substr($real, \strlen(\rtrim($root, '/'))), '/');
    }

    /**
     * @return array{ok: bool, kind?: string, name?: string, note?: string, text?: string, data?: string, error?: string}
     */
    public function load(string $path): array
    {
        $root = $this->settings->get('workspace');

        if (!\is_string($root) || !\is_dir($root)) {
            return ['ok' => false, 'error' => 'choose a working folder first — attachments come from there'];
        }

        $real = \realpath($path);

        if ($real === false || !\is_file($real)) {
            return ['ok' => false, 'error' => 'no such file'];
        }

        if (!\str_starts_with($real, \rtrim((string) \realpath($root), '/') . '/')) {
            return ['ok' => false, 'error' => 'refused: that file is outside the working folder'];
        }

        $name = \basename($real);
        $extension = \strtolower(\pathinfo($real, \PATHINFO_EXTENSION));
        $size = (int) (@\filesize($real) ?: 0);

        if (\in_array($extension, self::IMAGES, true)) {
            if ($size > self::MAX_IMAGE) {
                return ['ok' => false, 'error' => 'that image is larger than ' . self::mb(self::MAX_IMAGE)];
            }

            $model = $this->vision?->chosen();

            if ($model === null) {
                return [
                    'ok' => false,
                    'error' => 'no vision model is installed, and a text-only model would invent an answer about a '
                        . 'picture rather than refuse — install one with: ollama pull moondream',
                ];
            }

            $bytes = @\file_get_contents($real);

            if (!\is_string($bytes) || $bytes === '') {
                return ['ok' => false, 'error' => 'could not read that image'];
            }

            return [
                'ok' => true,
                'kind' => 'image',
                'name' => $name,
                'data' => \base64_encode($bytes),
                'note' => $name . ' (image, ' . self::kb($size) . ') — it will be looked at by ' . $model,
            ];
        }

        if (!\in_array($extension, self::TEXT, true)) {
            return ['ok' => false, 'error' => 'that file type is not one this app can hand to a model (' . $extension . ')'];
        }

        $text = @\file_get_contents($real, false, null, 0, self::MAX_TEXT + 1);

        if (!\is_string($text)) {
            return ['ok' => false, 'error' => 'could not read that file'];
        }

        $capped = \strlen($text) > self::MAX_TEXT;

        if ($capped) {
            $text = \substr($text, 0, self::MAX_TEXT);
        }

        return [
            'ok' => true,
            'kind' => 'text',
            'name' => $name,
            'text' => $text,
            'note' => $name . ' (' . $extension . ', ' . self::kb($size) . ')' . ($capped ? ' — capped at ' . self::kb(self::MAX_TEXT) : ''),
        ];
    }

    private static function kb(int $bytes): string
    {
        return $bytes >= 1_048_576
            ? \number_format($bytes / 1_048_576, 1) . ' MB'
            : \number_format($bytes / 1_024, 1) . ' KB';
    }

    private static function mb(int $bytes): string
    {
        return \number_format($bytes / 1_048_576, 0) . ' MB';
    }
}
