<?php

declare(strict_types=1);

/**
 * The governed endpoint, without the window.
 *
 * Same classes, same governor, same port — so it can be measured from a
 * terminal, and so an agent can be pointed at it when the app is not open:
 *
 *     php tools/harness.php --profile=whisper --seconds=600
 *     OLLAMA_HOST=http://127.0.0.1:11435 ollama run qwen3:8b
 *
 * It prints one line a second so a fan curve can be argued about with numbers
 * instead of impressions.
 */

use App\Governor;
use App\Guard;
use App\Hardware;
use App\Ollama;

require __DIR__ . '/../vendor/autoload.php';

$options = \getopt('', ['profile::', 'seconds::', 'silent']);
$seconds = (int) ($options['seconds'] ?? 300);
$silent = \array_key_exists('silent', $options);

$home = (string) (\getenv('HOME') ?: \sys_get_temp_dir());
$configRoot = (string) (\getenv('XDG_CONFIG_HOME') ?: $home . '/.config');

$hardware = new Hardware();
$governor = new Governor();

if (\is_string($options['profile'] ?? null)) {
    $governor->usePreset($options['profile']);
}

$ollama = new Ollama(logPath: $configRoot . '/quiesce/ollama.log');
$guard = new Guard($ollama, $governor, $hardware);

$listening = $guard->listen();

if (!($listening['ok'] ?? false)) {
    \fwrite(\STDERR, 'could not listen: ' . (string) ($listening['error'] ?? 'unknown') . "\n");
    exit(1);
}

$probe = $ollama->probe();

if (!$probe['up']) {
    \fwrite(\STDERR, 'ollama is not answering on ' . Ollama::HOST . ':' . Ollama::PORT
        . " — start it, or press Start in the window\n");
}

\printf("quiesce endpoint on %s  profile=%s  ceiling=%s °C\n", $guard->baseUrl(), $governor->preset(), $governor->ceiling());

$started = \microtime(true);
$lastSample = 0.0;

while (\microtime(true) - $started < $seconds) {
    $guard->tick();
    \usleep(120_000);

    $now = \microtime(true);

    if ($silent || $now - $lastSample < 1.0) {
        continue;
    }

    $lastSample = $now;
    $gpu = $hardware->gpu();
    $stats = $guard->stats();

    \printf(
        "[%4ds] %5s °C  %6s W  util %3s%%  fan %3s%%  duty %3s%%  queue %d  %s%s\n",
        (int) ($now - $started),
        $gpu['temp'] === null ? '—' : \number_format((float) $gpu['temp'], 0),
        $gpu['power'] === null ? '—' : \number_format((float) $gpu['power'], 0),
        $gpu['util'] === null ? '—' : \number_format((float) $gpu['util'], 0),
        $gpu['fan'] === null ? '—' : \number_format((float) $gpu['fan'], 0),
        \number_format($governor->duty() * 100, 0),
        $stats['queued'],
        $governor->reason(),
        $stats['inflight'] === null ? '' : ' — ' . $stats['inflight']['model'] . ' ' . $stats['inflight']['seconds'] . 's',
    );
}

\printf(
    "\ngenerations %d · held %d · paced %d · refused %d · failed %d\n",
    $guard->stats()['counters']['generations'],
    $guard->stats()['counters']['held'],
    $guard->stats()['counters']['paced'],
    $guard->stats()['counters']['refused'],
    $guard->stats()['counters']['failed'],
);
