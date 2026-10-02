<?php

declare(strict_types=1);

/**
 * The image worker: one generation, in its own process.
 *
 *     php tools/generate.php <base64 json payload>
 *
 * It exists so the window never waits: diffusion takes tens of seconds, and the
 * application loop is single-threaded. The worker talks to a local
 * AUTOMATIC1111-compatible server, writes the PNGs into the images folder, and
 * prints one JSON line for the app to read:
 *
 *     {"ok":true,"files":["1712…-a-red-cube.png"]}
 */

use App\Http;

require __DIR__ . '/../vendor/autoload.php';

$raw = \base64_decode((string) ($argv[1] ?? ''), true);
$payload = \is_string($raw) ? \json_decode($raw, true) : null;

$fail = static function (string $message): never {
    \fwrite(\STDOUT, \json_encode(['ok' => false, 'error' => $message]) . "\n");
    exit(1);
};

if (!\is_array($payload)) {
    $fail('the worker was not given a payload');
}

$url = (string) ($payload['url'] ?? '');
$prompt = (string) ($payload['prompt'] ?? '');
$steps = (int) ($payload['steps'] ?? 20);
$size = (int) ($payload['size'] ?? 512);
$directory = (string) ($payload['directory'] ?? '');

$host = (string) \parse_url($url, \PHP_URL_HOST);
$port = (int) (\parse_url($url, \PHP_URL_PORT) ?: 80);

try {
    $http = new Http($host, $port);
} catch (\RuntimeException $e) {
    $fail($e->getMessage());
}

if (!\is_dir($directory) && !@\mkdir($directory, 0o755, true)) {
    $fail('could not create ' . $directory);
}

$body = [
    'prompt' => $prompt,
    'negative_prompt' => (string) ($payload['negative'] ?? ''),
    'steps' => $steps,
    'width' => $size,
    'height' => $size,
    'cfg_scale' => (float) ($payload['cfg'] ?? 7),
    'sampler_name' => (string) ($payload['sampler'] ?? 'Euler a'),
    'batch_size' => 1,
    'n_iter' => 1,
    // The app keeps the pictures it made in its own images folder; there is no
    // reason to leave a second copy in the server's output directory.
    'save_images' => false,
];

// LoRAs go in the structured field, by name. The engine deliberately ignores
// `<lora:...>` tags inside the prompt; the app parses those and hands them over
// here, because a tag the engine silently ignores is worse than an error.
$loras = \is_array($payload['loras'] ?? null) ? $payload['loras'] : [];

if ($loras !== []) {
    $body['lora'] = $loras;
}

$deadline = \microtime(true) + 900;

try {
    $stream = $http->open('POST', '/sdapi/v1/txt2img', $body, 30.0);
} catch (\RuntimeException $e) {
    $fail('the image server could not be reached: ' . $e->getMessage());
}

$response = '';
$status = null;

while (!$stream->finished() && \microtime(true) < $deadline) {
    $response .= $stream->pump();

    if (!$stream->finished()) {
        \usleep(100_000);
    }
}

$status = $stream->status;
$stream->close();

if ($status !== 200) {
    $fail('the image server answered HTTP ' . (string) $status . ': ' . \substr(\trim($response), 0, 300));
}

$decoded = \json_decode($response, true);
$images = \is_array($decoded['images'] ?? null) ? $decoded['images'] : [];

if ($images === []) {
    $fail('the image server returned no images');
}

$slug = static function (string $text): string {
    $slug = \strtolower(\preg_replace('/[^A-Za-z0-9 ]+/', ' ', $text) ?? '');
    $slug = \trim(\preg_replace('/\s+/', '-', $slug) ?? '', '-');

    return $slug === '' ? 'image' : \substr($slug, 0, 48);
};

$files = [];

foreach ($images as $index => $base64) {
    if (!\is_string($base64)) {
        continue;
    }

    $bytes = \base64_decode($base64, true);

    if (!\is_string($bytes) || $bytes === '') {
        continue;
    }

    // The server sends PNG or JPEG; sniff rather than trust the extension.
    $extension = \str_starts_with($bytes, "\xff\xd8") ? 'jpg' : 'png';

    $name = \date('Ymd-His') . '-' . $slug($prompt) . ($index > 0 ? '-' . ($index + 1) : '') . '.' . $extension;
    $path = $directory . '/' . $name;

    if (@\file_put_contents($path, $bytes) === false) {
        $fail('could not write ' . $path);
    }

    $files[] = $name;
}

if ($files === []) {
    $fail('none of the images could be decoded');
}

\fwrite(\STDOUT, \json_encode(['ok' => true, 'files' => $files]) . "\n");
exit(0);
