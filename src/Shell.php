<?php

declare(strict_types=1);

namespace App;

/**
 * Running commands, with a gate rather than a sandbox.
 *
 * Be clear about what this is. A command here runs as the reader, with the
 * working folder as its directory. That is not containment — PHP cannot sandbox
 * a subprocess on Linux without namespaces, and pretending otherwise would be
 * the most dangerous thing in this application. What this class provides is:
 *
 *   - a **verdict** per command: `read` runs immediately, `write` waits for the
 *     reader to allow it, `never` is refused outright;
 *   - the gate exists because a local model plus a fetched web page is a prompt
 *     injection waiting to happen, and "run this" is the payload that matters;
 *   - a working directory, a timeout, and an output ceiling;
 *   - no shell is spawned for a `never` command, so nothing can be smuggled past
 *     it.
 *
 * Classification is best-effort and says so. It reads the command as a string
 * because that is what the model produced; a reader who wants stronger
 * containment should run the app as a separate user with access only to the
 * chosen folder.
 */
final class Shell
{
    public const int DEFAULT_TIMEOUT = 60;

    public const int MAX_TIMEOUT = 600;

    public const int MAX_OUTPUT = 32_768;

    /**
     * Refused whatever the reader says. Anything that can escalate privilege,
     * repartition a disk, or shut the machine down has no business being called
     * by a model, asked for politely or not.
     *
     * @var list<string>
     */
    private const array NEVER = [
        'sudo', 'doas', 'su ', 'su -', 'pkexec', 'passwd', 'visudo',
        'mkfs', 'fdisk', 'parted', 'mkswap', 'dd if=', 'dd of=',
        'shutdown', 'reboot', 'poweroff', 'halt', 'systemctl', 'service ',
        'insmod', 'modprobe', 'chroot', 'mount', 'umount', 'iptables', 'nft ',
        'crontab', 'rm -rf /', 'rm -fr /', ':(){', 'chmod -r 777 /',
    ];

    /**
     * Programs that only look. A read-only program still gets refused if the
     * command line contains anything that could write, or a shell operator that
     * changes what is being asked.
     *
     * @var list<string>
     */
    private const array READERS = [
        'ls', 'cat', 'head', 'tail', 'wc', 'grep', 'rg', 'find', 'file', 'stat',
        'tree', 'pwd', 'echo', 'du', 'df', 'diff', 'sort', 'uniq', 'cut', 'jq',
        'which', 'whoami', 'date', 'uptime', 'free', 'env', 'printenv',
        'php', 'node', 'python3', 'composer', 'npm', 'nvidia-smi', 'ollama',
    ];

    /** Read-only subcommands of git; anything else is a write. */
    private const array GIT_READERS = [
        'status', 'diff', 'log', 'show', 'branch', 'remote', 'ls-files', 'ls-tree',
        'rev-parse', 'blame', 'describe', 'shortlog', 'tag', 'stash?', 'config',
    ];

    /** Flags and programs that write, even inside a read-only program. */
    private const array WRITERS = [
        'tee', 'xargs', 'rm', 'mv', 'cp', 'mkdir', 'rmdir', 'touch', 'truncate',
        'ln', 'chmod', 'chown', 'sed', 'perl', 'awk', 'sh', 'bash', 'zsh', 'eval',
        'install', 'patch', 'tar', 'unzip', 'zip', 'wget', 'curl', 'make', 'ninja',
        'pip', 'pip3', 'gem', 'cargo', 'go', 'docker', 'podman', 'kill', 'pkill',
    ];

    /** @var array<string, mixed>|null */
    private ?array $job = null;

    public function __construct(private readonly Settings $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('shell', true);
    }

    public function setEnabled(bool $enabled): void
    {
        $this->settings->set('shell', $enabled);
        $this->settings->save();
    }

    /**
     * `read` runs, `write` asks first, `never` does not run at all.
     *
     * @return array{verdict: string, reason: string}
     */
    public function classify(string $command): array
    {
        $command = \trim($command);

        if ($command === '') {
            return ['verdict' => 'never', 'reason' => 'empty command'];
        }

        $lower = \strtolower($command);

        foreach (self::NEVER as $forbidden) {
            if (\str_contains($lower, $forbidden)) {
                return ['verdict' => 'never', 'reason' => 'refused outright: "' . \trim($forbidden) . '" is not something a model may run here'];
            }
        }

        // Shell operators change what is being asked for: a pipe into tee, a
        // redirect, a command substitution. Conservative on purpose.
        if (\preg_match('/[><`$;|&]/', $command) === 1) {
            return ['verdict' => 'write', 'reason' => 'contains a shell operator, so its effect cannot be read off the command name'];
        }

        $tokens = \preg_split('/\s+/', $command) ?: [];
        $program = \basename((string) ($tokens[0] ?? ''));

        foreach (self::WRITERS as $writer) {
            foreach ($tokens as $token) {
                if (\basename($token) === $writer || \str_starts_with($token, '--output') || $token === '-i' || $token === '-delete' || $token === '-exec') {
                    return ['verdict' => 'write', 'reason' => '"' . $token . '" changes something'];
                }
            }
        }

        if ($program === 'git') {
            $sub = (string) ($tokens[1] ?? '');

            if (\in_array($sub, self::GIT_READERS, true)) {
                return ['verdict' => 'read', 'reason' => 'git ' . $sub . ' only reads'];
            }

            return ['verdict' => 'write', 'reason' => 'git ' . ($sub === '' ? '(no subcommand)' : $sub) . ' can change the repository'];
        }

        if (\in_array($program, self::READERS, true)) {
            return ['verdict' => 'read', 'reason' => $program . ' only reads'];
        }

        return ['verdict' => 'write', 'reason' => '"' . $program . '" is not a program this app knows to be read-only'];
    }

    /**
     * Start a command, non-blocking, in the working folder.
     *
     * @return array{ok: bool, handle?: int, error?: string}
     */
    public function start(string $command, int $timeout = self::DEFAULT_TIMEOUT): array
    {
        $workspace = $this->settings->get('workspace');

        if (!\is_string($workspace) || !\is_dir($workspace)) {
            return ['ok' => false, 'error' => 'no working folder has been chosen yet'];
        }

        // Belt and braces: the agent checks the verdict before calling this, and
        // this checks again, so no future caller can be the one that forgets.
        if ($this->classify($command)['verdict'] === 'never') {
            return ['ok' => false, 'error' => $this->classify($command)['reason']];
        }

        if ($this->job !== null) {
            return ['ok' => false, 'error' => 'a command is already running'];
        }

        $timeout = \max(1, \min($timeout, self::MAX_TIMEOUT));

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $environment = [
            'PATH' => (string) (\getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'HOME' => (string) (\getenv('HOME') ?: '/tmp'),
            'LANG' => (string) (\getenv('LANG') ?: 'C.UTF-8'),
            'TERM' => 'dumb',
            'QUIESCE' => '1',
        ];

        $process = @\proc_open(['/bin/sh', '-c', $command], $descriptors, $pipes, $workspace, $environment);

        if (!\is_resource($process)) {
            return ['ok' => false, 'error' => 'could not start the command'];
        }

        foreach ([1, 2] as $fd) {
            \stream_set_blocking($pipes[$fd], false);
        }

        $this->job = [
            'process' => $process,
            'pipes' => $pipes,
            'command' => $command,
            'started' => \microtime(true),
            'deadline' => \microtime(true) + $timeout,
            'timeout' => $timeout,
            'output' => '',
            'truncated' => false,
            'done' => false,
            'code' => null,
        ];

        return ['ok' => true, 'handle' => 1];
    }

    /**
     * Read what has arrived since the last call. Never blocks.
     *
     * @return array{running: bool, output: string, code: ?int, truncated: bool, seconds: float}
     */
    public function poll(): array
    {
        if ($this->job === null) {
            return ['running' => false, 'output' => '', 'code' => null, 'truncated' => false, 'seconds' => 0.0];
        }

        $fresh = '';

        foreach ([1, 2] as $fd) {
            $chunk = @\fread($this->job['pipes'][$fd], 8_192);

            if (\is_string($chunk) && $chunk !== '') {
                $fresh .= $chunk;
            }
        }

        if ($fresh !== '') {
            if (\strlen($this->job['output']) < self::MAX_OUTPUT) {
                $this->job['output'] .= $fresh;
            } else {
                $this->job['truncated'] = true;
            }
        }

        $status = \proc_get_status($this->job['process']);

        if (($status['running'] ?? false) === false) {
            $this->job['done'] = true;
            $this->job['code'] = (int) ($status['exitcode'] ?? 0);

            // One last read: output can arrive after the process is reaped.
            foreach ([1, 2] as $fd) {
                $chunk = @\stream_get_contents($this->job['pipes'][$fd]);

                if (\is_string($chunk) && $chunk !== '' && \strlen($this->job['output']) < self::MAX_OUTPUT) {
                    $this->job['output'] .= $chunk;
                }
            }
        } elseif (\microtime(true) > $this->job['deadline']) {
            $this->kill();
            $this->job['done'] = true;
            $this->job['code'] = 124;
            $this->job['output'] .= "\n\n" . '[stopped after ' . $this->job['timeout'] . 's]';
        }

        if (\strlen($this->job['output']) > self::MAX_OUTPUT) {
            $this->job['output'] = \substr($this->job['output'], 0, self::MAX_OUTPUT);
            $this->job['truncated'] = true;
        }

        return [
            'running' => !$this->job['done'],
            'output' => $fresh,
            'code' => $this->job['done'] ? $this->job['code'] : null,
            'truncated' => $this->job['truncated'],
            'seconds' => \round(\microtime(true) - $this->job['started'], 1),
        ];
    }

    /** Everything the command produced, once it has finished. */
    public function collect(): string
    {
        if ($this->job === null) {
            return '';
        }

        $output = \rtrim($this->job['output']);
        $code = $this->job['code'];

        $this->job = null;

        $suffix = $code === null || $code === 0 ? '' : "\n(exit code " . $code . ')';

        return $output === '' ? '(no output)' . $suffix : $output . $suffix;
    }

    public function running(): bool
    {
        return $this->job !== null && !$this->job['done'];
    }

    public function command(): string
    {
        return (string) ($this->job['command'] ?? '');
    }

    public function stop(): void
    {
        if ($this->job === null) {
            return;
        }

        $this->kill();
        $this->job = null;
    }

    private function kill(): void
    {
        if ($this->job === null) {
            return;
        }

        $process = $this->job['process'];

        // The shell may have children; kill the group it was started in.
        $pid = \proc_get_status($process)['pid'] ?? null;

        if (\is_int($pid)) {
            @\posix_kill(-$pid, \SIGKILL);
            @\posix_kill($pid, \SIGKILL);
        }

        @\proc_terminate($process, \SIGKILL);

        foreach ($this->job['pipes'] as $pipe) {
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
        }

        @\proc_close($process);
    }
}
