<?php

declare(strict_types=1);

/**
 * A stand-in for an AUTOMATIC1111 server, for tests: enough of the API for the
 * client to be tested against a real HTTP server rather than a mock.
 */

$path = (string) \parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), \PHP_URL_PATH);

\header('Content-Type: application/json');

if ($path === '/sdapi/v1/sd-models') {
    echo \json_encode([['model_name' => 'fixture-model.safetensors', 'title' => 'fixture']]);

    return;
}

if ($path === '/sdapi/v1/options') {
    echo \json_encode(['sd_model_checkpoint' => 'fixture-model.safetensors']);

    return;
}

if ($path === '/sdapi/v1/progress') {
    echo \json_encode(['progress' => 0.42, 'eta_relative' => 1.5]);

    return;
}

if ($path === '/sdapi/v1/txt2img') {
    $body = \json_decode((string) \file_get_contents('php://input'), true);
    $size = (int) ($body['width'] ?? 8);

    // A real, valid PNG, drawn here so the client's decoding is exercised too.
    $image = \imagecreatetruecolor(\max(1, \min($size, 64)), \max(1, \min($size, 64)));
    \imagefill($image, 0, 0, \imagecolorallocate($image, 200, 40, 40));
    \ob_start();
    \imagepng($image);
    $png = (string) \ob_get_clean();
    \imagedestroy($image);

    echo \json_encode(['images' => [\base64_encode($png)]]);

    return;
}

\http_response_code(404);
echo \json_encode(['error' => 'the fixture only serves the sdapi endpoints']);
