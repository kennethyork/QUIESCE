<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Ollama;
use App\Settings;
use App\Vision;
use PHPUnit\Framework\TestCase;

/**
 * Vision: the refusals are as important as the answers. An image outside the
 * working folder is not looked at, and a machine with no vision model is told so
 * rather than handed a picture to hallucinate over.
 */
final class VisionTest extends TestCase
{
    private string $root;

    private Vision $vision;

    private Settings $settings;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/quiesce-vision-' . \bin2hex(\random_bytes(4));
        \mkdir($this->root . '/pictures', 0o755, true);

        $this->settings = new Settings($this->root . '/settings.json');
        $this->settings->set('workspace', $this->root);

        $this->vision = new Vision($this->settings, new Ollama(logPath: '/tmp/quiesce-vision.log'));
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->root . '/pictures/*') ?: [] as $file) {
            @\unlink($file);
        }

        foreach (\glob($this->root . '/*') ?: [] as $file) {
            \is_dir($file) ? @\rmdir($file) : @\unlink($file);
        }

        @\rmdir($this->root);
    }

    public function testItKnowsWhichModelsCanSee(): void
    {
        // Whatever is installed, the answer must be a list of names, not a guess.
        foreach ($this->vision->models() as $model) {
            self::assertIsString($model);
            self::assertNotSame('', $model);
        }
    }

    public function testItRefusesToReadOutsideTheFolder(): void
    {
        $outside = \sys_get_temp_dir() . '/quiesce-outside-' . \bin2hex(\random_bytes(4)) . '.png';
        \file_put_contents($outside, 'not really an image');

        foreach (['../' . \basename($outside), '/etc/passwd', $outside] as $path) {
            $result = $this->vision->describe($path);

            if ($this->vision->chosen() === null) {
                self::assertFalse($result['ok'], 'no model, so nothing should be read either way');
            } else {
                self::assertFalse($result['ok'], $path . ' should be refused');
                self::assertStringContainsString('refused', $result['output']);
            }
        }

        @\unlink($outside);
    }

    public function testItRefusesSomethingThatIsNotAnImage(): void
    {
        \file_put_contents($this->root . '/notes.md', 'this is not a picture');

        $result = $this->vision->describe('notes.md');

        self::assertFalse($result['ok']);
    }

    public function testNoFolderMeansNothingIsLookedAt(): void
    {
        $vision = new Vision(new Settings($this->root . '/empty.json'), new Ollama(logPath: '/tmp/quiesce-vision.log'));

        $result = $vision->describe('anything.png');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('no working folder', $result['output']);
    }

    /**
     * The live path, against the real vision model — skipped when there is none,
     * because a skipped test that says why is worth more than a mock that agrees
     * with whatever the code does.
     */
    public function testItCanActuallyDescribeAnImage(): void
    {
        $model = $this->vision->chosen();

        if ($model === null) {
            self::markTestSkipped('no vision model installed (ollama pull moondream)');
        }

        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('gd is not available to build a test image');
        }

        $image = \imagecreatetruecolor(256, 256);
        \imagefill($image, 0, 0, \imagecolorallocate($image, 20, 160, 60));
        \imagefilledellipse($image, 128, 128, 120, 120, \imagecolorallocate($image, 250, 250, 250));
        \imagepng($image, $this->root . '/pictures/shape.png');
        \imagedestroy($image);

        $result = $this->vision->describe('pictures/shape.png', 'What colour is the shape in the middle? Answer in a few words.');

        self::assertTrue($result['ok'], $result['output']);
        self::assertStringContainsString($model, $result['output'], 'it says which model looked');
        self::assertGreaterThan(3, \strlen($result['output']));
    }
}
