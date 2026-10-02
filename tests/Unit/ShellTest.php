<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Settings;
use App\Shell;
use PHPUnit\Framework\TestCase;

/**
 * The command gate, tested where it matters: what it lets through without
 * asking, what it stops to ask about, and what it refuses to run at all.
 *
 * The refusals are the point. A local model reading a fetched web page is the
 * exact shape of a prompt-injection payload, and "run this" is the payload that
 * matters, so `never` has to hold even when the reader says yes.
 */
final class ShellTest extends TestCase
{
    private string $root;

    private Shell $shell;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/quiesce-shell-' . \bin2hex(\random_bytes(4));
        \mkdir($this->root, 0o755, true);

        $settings = new Settings($this->root . '/settings.json');
        $settings->set('workspace', $this->root);

        $this->shell = new Shell($settings);
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->root)) {
            foreach (\glob($this->root . '/*') ?: [] as $file) {
                \unlink($file);
            }

            \rmdir($this->root);
        }
    }

    public function testLookingAtThingsRunsWithoutAsking(): void
    {
        foreach (['ls -la', 'cat notes.md', 'git status', 'git diff', 'grep -rn foo src', 'pwd'] as $command) {
            self::assertSame('read', $this->shell->classify($command)['verdict'], $command);
        }
    }

    public function testChangingThingsAsksFirst(): void
    {
        foreach (['rm -rf build', 'mv a b', 'git commit -m "x"', 'git checkout main', 'npm install', 'mkdir new'] as $command) {
            self::assertSame('write', $this->shell->classify($command)['verdict'], $command);
        }
    }

    public function testShellOperatorsAreNeverAssumedHarmless(): void
    {
        foreach (['ls > list.txt', 'cat a | tee b', 'echo hi && rm x', 'ls `pwd`', 'cat $(ls)'] as $command) {
            self::assertSame('write', $this->shell->classify($command)['verdict'], $command);
        }
    }

    public function testPrivilegeAndDisksAreRefusedOutright(): void
    {
        foreach ([
            'sudo ls',
            'sudo -i',
            'shutdown -h now',
            'mkfs.ext4 /dev/sda1',
            'dd if=/dev/zero of=/dev/sda',
            'systemctl restart ollama',
            'rm -rf /',
            'chmod -R 777 /',
        ] as $command) {
            self::assertSame('never', $this->shell->classify($command)['verdict'], $command);
        }
    }

    public function testARefusedCommandCannotBeStartedAtAll(): void
    {
        $started = $this->shell->start('sudo ls');

        self::assertFalse($started['ok']);
        self::assertStringContainsString('refused outright', (string) $started['error']);
    }

    public function testACommandRunsInTheWorkingFolderAndItsOutputComesBack(): void
    {
        $started = $this->shell->start('echo hello from the folder');
        self::assertTrue($started['ok']);

        $output = $this->drain();

        self::assertStringContainsString('hello from the folder', $output);

        $where = $this->shell->start('pwd');
        self::assertTrue($where['ok']);
        self::assertStringContainsString(\realpath($this->root), $this->drain());
    }

    public function testAFailureIsReportedWithItsExitCode(): void
    {
        $this->shell->start('ls /definitely-not-here');

        self::assertStringContainsString('exit code', $this->drain());
    }

    public function testACommandThatOverstaysIsStopped(): void
    {
        $started = $this->shell->start('sleep 30', 1);
        self::assertTrue($started['ok']);

        $startedAt = \microtime(true);
        $output = $this->drain();

        self::assertLessThan(10.0, \microtime(true) - $startedAt, 'it was stopped, not waited out');
        self::assertStringContainsString('stopped after 1s', $output);
    }

    public function testOutputIsCapped(): void
    {
        $this->shell->start('seq 1 200000');

        $output = $this->drain();

        self::assertLessThan(Shell::MAX_OUTPUT + 200, \strlen($output));
    }

    public function testOnlyOneCommandRunsAtATime(): void
    {
        $this->shell->start('sleep 1');

        $second = $this->shell->start('ls');

        self::assertFalse($second['ok']);
        self::assertStringContainsString('already running', (string) $second['error']);

        $this->shell->stop();
    }

    public function testNoFolderMeansNoCommands(): void
    {
        $settings = new Settings($this->root . '/other.json');
        $shell = new Shell($settings);

        $started = $shell->start('ls');

        self::assertFalse($started['ok']);
        self::assertStringContainsString('no working folder', (string) $started['error']);
    }

    /** Wait for the job to finish, collecting everything it wrote. */
    private function drain(float $limit = 20.0): string
    {
        $deadline = \microtime(true) + $limit;

        while (\microtime(true) < $deadline) {
            $poll = $this->shell->poll();

            if (!$poll['running']) {
                return $this->shell->collect();
            }

            \usleep(20_000);
        }

        $this->shell->stop();

        return '';
    }
}
