<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * The only way this application talks to a model.
 *
 * Loopback, one port, no configuration: the address is a constant, not a
 * setting, because a setting is a thing that can point somewhere else by
 * accident. If Ollama is not up, this says so and offers to start it rather than
 * falling back to anything remote.
 */
final class Ollama
{
    public const string HOST = '127.0.0.1';

    public const int PORT = 11434;

    /** @var resource|null */
    private static $serving = null;

    public function __construct(
        private readonly Http $http = new Http(self::HOST, self::PORT),
        private readonly string $logPath = '',
    ) {}

    public function http(): Http
    {
        return $this->http;
    }

    /**
     * @return array{up: bool, version: ?string, error: ?string, host: string, port: int}
     */
    public function probe(): array
    {
        $base = ['host' => self::HOST, 'port' => self::PORT, 'version' => null, 'error' => null];

        try {
            $response = $this->http->json('GET', '/api/version', null, 2.0);
        } catch (RuntimeException $e) {
            return ['up' => false, 'error' => $e->getMessage()] + $base;
        }

        if ($response['status'] !== 200) {
            return ['up' => false, 'error' => \sprintf('ollama answered HTTP %d', $response['status'])] + $base;
        }

        $version = $response['json']['version'] ?? null;

        return ['up' => true, 'version' => \is_string($version) ? $version : null] + $base;
    }

    /** @return list<array<string, mixed>> */
    public function models(): array
    {
        try {
            $response = $this->http->json('GET', '/api/tags', null, 5.0);
        } catch (RuntimeException) {
            return [];
        }

        $models = $response['json']['models'] ?? [];

        if (!\is_array($models)) {
            return [];
        }

        $out = [];

        foreach ($models as $model) {
            if (!\is_array($model) || !isset($model['name'])) {
                continue;
            }

            $details = \is_array($model['details'] ?? null) ? $model['details'] : [];

            $out[] = [
                'name' => (string) $model['name'],
                'size' => (int) ($model['size'] ?? 0),
                'modified' => (string) ($model['modified_at'] ?? ''),
                'parameters' => (string) ($details['parameter_size'] ?? ''),
                'quantization' => (string) ($details['quantization_level'] ?? ''),
                'family' => (string) ($details['family'] ?? ''),
            ];
        }

        \usort($out, static fn (array $a, array $b): int => \strcmp($a['name'], $b['name']));

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function loaded(): array
    {
        try {
            $response = $this->http->json('GET', '/api/ps', null, 5.0);
        } catch (RuntimeException) {
            return [];
        }

        $models = $response['json']['models'] ?? [];

        if (!\is_array($models)) {
            return [];
        }

        $out = [];

        foreach ($models as $model) {
            if (!\is_array($model)) {
                continue;
            }

            $out[] = [
                'name' => (string) ($model['name'] ?? ''),
                'size' => (int) ($model['size'] ?? 0),
                'vram' => (int) ($model['size_vram'] ?? 0),
                'context' => (int) ($model['context_length'] ?? 0),
                'expires' => (string) ($model['expires_at'] ?? ''),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function show(string $model): array
    {
        try {
            $response = $this->http->json('POST', '/api/show', ['model' => $model], 8.0);
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }

        if ($response['status'] !== 200 || !\is_array($response['json'])) {
            return ['error' => \sprintf('ollama answered HTTP %d', $response['status'])];
        }

        $json = $response['json'];
        $info = \is_array($json['model_info'] ?? null) ? $json['model_info'] : [];
        $context = null;

        foreach ($info as $key => $value) {
            if (\is_string($key) && \str_ends_with($key, '.context_length') && \is_int($value)) {
                $context = $value;

                break;
            }
        }

        return [
            'name' => $model,
            'context_length' => $context,
            'parameters' => (string) ($json['details']['parameter_size'] ?? ''),
            'quantization' => (string) ($json['details']['quantization_level'] ?? ''),
            'capabilities' => \is_array($json['capabilities'] ?? null) ? $json['capabilities'] : [],
        ];
    }

    /**
     * Ask Ollama to drop a model out of VRAM now rather than at its keep-alive.
     *
     * @return array<string, mixed>
     */
    public function unload(string $model): array
    {
        try {
            $response = $this->http->json('POST', '/api/generate', [
                'model' => $model,
                'prompt' => '',
                'keep_alive' => 0,
            ], 20.0);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => $response['status'] === 200, 'status' => $response['status']];
    }

    /**
     * Load a model without asking it anything, so the first real question is not
     * the one that pays the load cost (and the load spike is the loudest moment).
     *
     * @return array<string, mixed>
     */
    public function preload(string $model, array $options, string $keepAlive): array
    {
        try {
            $response = $this->http->json('POST', '/api/generate', [
                'model' => $model,
                'prompt' => '',
                'options' => $options,
                'keep_alive' => $keepAlive,
            ], 120.0);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => $response['status'] === 200, 'status' => $response['status']];
    }

    public function binary(): ?string
    {
        foreach ([
            \getenv('HOME') . '/.local/bin/ollama',
            '/usr/local/bin/ollama',
            '/usr/bin/ollama',
        ] as $path) {
            if (\is_string($path) && \is_executable($path)) {
                return $path;
            }
        }

        $found = @\shell_exec('command -v ollama 2>/dev/null');

        return \is_string($found) && \trim($found) !== '' ? \trim($found) : null;
    }

    public function logPath(): string
    {
        return $this->logPath;
    }

    /**
     * Start `ollama serve`, bound to loopback, detached from this process.
     *
     * Not a shell-out to a stranger: the same binary the reader's own `ollama`
     * command uses, with OLLAMA_HOST pinned so it cannot end up listening on the
     * network, and stdout kept in a log so a failure has a reason attached.
     *
     * @return array<string, mixed>
     */
    public function serve(): array
    {
        if ($this->probe()['up']) {
            return ['ok' => true, 'already' => true];
        }

        $binary = $this->binary();

        if ($binary === null) {
            return ['ok' => false, 'error' => 'no ollama binary found on PATH or in ~/.local/bin'];
        }

        $log = $this->logPath === '' ? \sys_get_temp_dir() . '/quiesce-ollama.log' : $this->logPath;

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];

        // A spawned engine needs the reader's own environment: HOME in
        // particular, because the `ollama` on PATH may be a wrapper that
        // resolves its model store and binary relative to it. Handing it an
        // empty environment is how you get a serve that exits instantly and
        // says `/.config/...: not found`.
        $env = [
            'HOME' => (string) (\getenv('HOME') ?: ''),
            'PATH' => (string) (\getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'OLLAMA_HOST' => \sprintf('http://%s:%d', self::HOST, self::PORT),
            'LANG' => (string) (\getenv('LANG') ?: 'C.UTF-8'),
        ];

        foreach ([
            'XDG_CONFIG_HOME', 'XDG_DATA_HOME', 'USER', 'LOGNAME',
            'OLLAMA_MODELS', 'OLLAMA_ORIGINS', 'OLLAMA_KEEP_ALIVE', 'OLLAMA_NUM_PARALLEL',
            'OLLAMA_FLASH_ATTENTION', 'OLLAMA_KV_CACHE_TYPE', 'OLLAMA_GPU_OVERHEAD', 'LD_LIBRARY_PATH',
        ] as $key) {
            $value = \getenv($key);

            if (\is_string($value) && $value !== '') {
                $env[$key] = $value;
            }
        }

        $process = @\proc_open([$binary, 'serve'], $descriptors, $pipes, null, $env);

        if (!\is_resource($process)) {
            return ['ok' => false, 'error' => 'could not start ollama serve'];
        }

        // Hold the handle: dropping it would let PHP shut the child down on exit,
        // and the whole point is that the engine outlives this window.
        self::$serving = $process;

        // Only a short wait: the window stays responsive and the caller polls for
        // the engine rather than the whole application freezing on a cold load.
        $deadline = \microtime(true) + 3.0;

        while (\microtime(true) < $deadline) {
            \usleep(300_000);

            if ($this->probe()['up']) {
                return ['ok' => true, 'started' => true, 'binary' => $binary, 'log' => $log];
            }
        }

        $status = \proc_get_status($process);

        if (($status['running'] ?? false) === false) {
            $tail = @\file_get_contents($log);

            return [
                'ok' => false,
                'error' => 'ollama serve exited immediately',
                'log' => $log,
                'tail' => \is_string($tail) ? \substr($tail, -800) : '',
            ];
        }

        return ['ok' => true, 'started' => true, 'pending' => true, 'binary' => $binary, 'log' => $log];
    }
}
