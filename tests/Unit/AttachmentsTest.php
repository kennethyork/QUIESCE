<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Attachments;
use App\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Attachments: the refusals carry the weight here. An image handed to a
 * text-only model does not fail — the model answers something, confidently and
 * wrongly — so the app must refuse it, and a file outside the working folder must
 * never be posted to a model server just because a file picker could reach it.
 */
final class AttachmentsTest extends TestCase
{
    private string $root;

    private string $config;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/quiesce-attach-' . \bin2hex(\random_bytes(4));
        \mkdir($this->root, 0o755, true);

        \file_put_contents($this->root . '/notes.md', "# Notes\n\nSome text to attach.\n");
        \file_put_contents($this->root . '/binary.dat', \random_bytes(64));

        if (\function_exists('imagecreatetruecolor')) {
            $image = \imagecreatetruecolor(32, 32);
            \imagepng($image, $this->root . '/picture.png');
            \imagedestroy($image);
        }

        $this->config = $this->root . '/settings.json';
        $settings = new Settings($this->config);
        $settings->set('workspace', $this->root);
        $settings->save();   // the loader reads the file, so the file has to have it
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->root . '/*') ?: [] as $file) {
            \is_dir($file) ? @\rmdir($file) : @\unlink($file);
        }

        @\rmdir($this->root);
    }

    public function testATextFileIsInlinedWithANote(): void
    {
        $result = $this->attachments()->load($this->root . '/notes.md');

        self::assertTrue($result['ok']);
        self::assertSame('text', $result['kind']);
        self::assertStringContainsString('Some text to attach', (string) $result['text']);
        self::assertStringContainsString('notes.md', (string) $result['note']);
    }

    public function testALongFileIsCappedAndSaysSo(): void
    {
        \file_put_contents($this->root . '/long.txt', \str_repeat('x', Attachments::MAX_TEXT + 500));

        $result = $this->attachments()->load($this->root . '/long.txt');

        self::assertTrue($result['ok']);
        self::assertSame(Attachments::MAX_TEXT, \strlen((string) $result['text']));
        self::assertStringContainsString('capped', (string) $result['note']);
    }

    public function testAnImageIsRefusedWhenNothingCanSeeIt(): void
    {
        $result = $this->attachments()->load($this->root . '/picture.png');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('no vision model', (string) $result['error']);
        self::assertStringContainsString('ollama pull moondream', (string) $result['error']);
    }

    public function testAnImageIsAttachedAsDataWhenAVisionModelExists(): void
    {
        $vision = $this->createStub(\App\Vision::class);
        $vision->method('chosen')->willReturn('moondream:latest');

        $result = (new Attachments(new Settings($this->config), $vision))->load($this->root . '/picture.png');

        self::assertTrue($result['ok']);
        self::assertSame('image', $result['kind']);
        self::assertNotSame('', (string) $result['data']);
        self::assertStringStartsWith("\x89PNG", (string) \base64_decode((string) $result['data'], true), 'and it is the file, not a path');
        self::assertStringContainsString('moondream', (string) $result['note']);
    }

    public function testAFileOutsideTheFolderIsRefused(): void
    {
        $outside = \sys_get_temp_dir() . '/quiesce-outside-' . \bin2hex(\random_bytes(4)) . '.txt';
        \file_put_contents($outside, 'not yours to send');

        $result = $this->attachments()->load($outside);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('outside the working folder', (string) $result['error']);

        @\unlink($outside);
    }

    public function testATypeItCannotReadIsRefusedByName(): void
    {
        $result = $this->attachments()->load($this->root . '/binary.dat');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('not one this app can hand to a model', (string) $result['error']);
    }

    public function testNoFolderMeansNothingCanBeAttached(): void
    {
        $settings = new Settings($this->root . '/empty.json');

        $result = (new Attachments($settings))->load($this->root . '/notes.md');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('choose a working folder', (string) $result['error']);
    }

    public function testAMissingFileIsRefused(): void
    {
        $result = $this->attachments()->load($this->root . '/nothing-here.md');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('no such file', (string) $result['error']);
    }

    private function attachments(): Attachments
    {
        return new Attachments(new Settings($this->config));
    }
}
