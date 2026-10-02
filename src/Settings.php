<?php

declare(strict_types=1);

namespace App;

/**
 * The few things worth remembering between launches.
 *
 * A file in the reader's own config directory, readable with `cat`, deletable
 * without this app's help. Nothing else about this application is persisted,
 * and nothing here is ever sent anywhere.
 */
final class Settings
{
    /** @var array<string, mixed> */
    private array $values;

    public function __construct(private readonly string $path)
    {
        $this->values = $this->read();
    }

    public function path(): string
    {
        return $this->path;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    /** @param array<string, mixed> $values */
    public function merge(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->values[(string) $key] = $value;
        }
    }

    public function save(): bool
    {
        $directory = \dirname($this->path);

        if (!\is_dir($directory)) {
            @\mkdir($directory, 0o755, true);
        }

        $json = \json_encode($this->values, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return false;
        }

        return @\file_put_contents($this->path, $json . "\n") !== false;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        if (!\is_file($this->path)) {
            return [];
        }

        $raw = @\file_get_contents($this->path);

        if (!\is_string($raw) || \trim($raw) === '') {
            return [];
        }

        $decoded = \json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
