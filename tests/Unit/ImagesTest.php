<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Images;
use App\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The image client, tested against a real HTTP server standing in for
 * AUTOMATIC1111 — because "it would probably work against A1111" is exactly the
 * kind of claim that turns out to be false when someone starts a real one.
 */
final class ImagesTest extends TestCase
{
    private string $root;

    private string $config;

    private Images $images;

    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/quiesce-images-' . \bin2hex(\random_bytes(4));
        \mkdir($this->root . '/images', 0o755, true);

        $this->config = $this->root . '/settings.json';
        $this->port = $this->startFakeServer();

        $settings = new Settings($this->config);
        $settings->set('image_url', 'http://127.0.0.1:' . $this->port);

        $this->images = new Images($settings, $this->root . '/images', \dirname(__DIR__, 2) . '/tools/generate.php');
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            @\proc_terminate($this->server, \SIGKILL);
            @\proc_close($this->server);
        }

        foreach (\glob($this->root . '/images/*') ?: [] as $file) {
            @\unlink($file);
        }

        @\rmdir($this->root . '/images');
        @\unlink($this->config);
        @\rmdir($this->root);
    }

    public function testItFindsTheServerAndListsItsModels(): void
    {
        $backend = $this->images->backend();

        self::assertTrue($backend['available']);
        self::assertSame('a1111', $backend['kind']);
        self::assertContains('fixture-model.safetensors', $backend['models']);
    }

    public function testItDrawsAndWritesARealPng(): void
    {
        $started = $this->images->start('a red cube on a table');
        self::assertTrue($started['ok'], (string) ($started['error'] ?? ''));

        $result = $this->wait();

        self::assertTrue($result['ok'], $result['error']);
        self::assertNotSame([], $result['files']);

        $file = $this->images->directory() . '/' . $result['files'][0];

        self::assertFileExists($file);
        self::assertStringStartsWith("\x89PNG", (string) \file_get_contents($file), 'and it is a real image');
        self::assertStringContainsString('a-red-cube-on-a-table', $result['files'][0], 'the name still says what it was');
    }

    public function testItRefusesAnAddressThatIsNotThisMachine(): void
    {
        $result = $this->images->setUrl('http://192.168.1.50:7860');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('only a server on this machine', (string) $result['error']);
    }

    public function testNoServerMeansItSaysSoRatherThanFailingOddly(): void
    {
        $settings = new Settings($this->root . '/other.json');
        $settings->set('image_url', 'http://127.0.0.1:9');
        $images = new Images($settings, $this->root . '/images', \dirname(__DIR__, 2) . '/tools/generate.php');

        self::assertFalse($images->available());

        $started = $images->start('anything');

        self::assertFalse($started['ok']);
        self::assertStringContainsString('no local image server', (string) $started['error']);
    }

    public function testAnEmptyPromptIsRefused(): void
    {
        $started = $this->images->start('   ');

        self::assertFalse($started['ok']);
        self::assertStringContainsString('nothing to draw', (string) $started['error']);
    }

    public function testASizeInThePathCannotEscapeTheImageFolder(): void
    {
        self::assertNull($this->images->path('../../etc/passwd'));
        self::assertNull($this->images->path('not-an-image.txt'));
        self::assertNull($this->images->path('image.png'));   // valid name, no such file
    }

    public function testTheStepCountIsBoundedSoARequestCannotPinTheCard(): void
    {
        $settings = new Settings($this->config);
        $images = new Images($settings, $this->root . '/images', \dirname(__DIR__, 2) . '/tools/generate.php');

        $images->setSteps(10_000);
        self::assertSame(Images::MAX_STEPS, $images->steps(), 'steps are clamped, not trusted');

        $images->setSize(9_999);
        self::assertSame(1_024, $images->size());
    }

    /** Start `php -S` on a free port and wait until it answers. */
    private function startFakeServer(): int
    {
        $router = __DIR__ . '/../fixtures/a1111-router.php';

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $port = \random_int(20_000, 40_000);

            $process = @\proc_open(
                [\PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
            );

            if (!\is_resource($process)) {
                continue;
            }

            for ($wait = 0; $wait < 40; $wait++) {
                \usleep(50_000);

                $probe = @\fsockopen('127.0.0.1', $port, $code, $message, 0.2);

                if (\is_resource($probe)) {
                    @\fclose($probe);
                    $this->server = $process;

                    return $port;
                }
            }

            @\proc_terminate($process, \SIGKILL);
            @\proc_close($process);
        }

        self::fail('could not start the fixture image server');
    }

    /** @return array{ok: bool, files: list<string>, error: string, seconds: float} */
    private function wait(float $limit = 30.0): array
    {
        $deadline = \microtime(true) + $limit;

        while (\microtime(true) < $deadline) {
            if (!$this->images->poll()['running']) {
                return $this->images->collect();
            }

            \usleep(50_000);
        }

        $this->images->stop();

        return ['ok' => false, 'files' => [], 'error' => 'the worker never finished', 'seconds' => 0.0];
    }
}
