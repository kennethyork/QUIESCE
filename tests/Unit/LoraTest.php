<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Images;
use PHPUnit\Framework\TestCase;

/**
 * LoRA tags, parsed by the app because the engine refuses to parse them.
 *
 * stable-diffusion.cpp deliberately ignores `<lora:...>` in a prompt and takes a
 * structured list instead, so the app does the reading — and the tag is removed
 * either way, because leaving it in sends the engine a word that means nothing.
 */
final class LoraTest extends TestCase
{
    public function testANameBecomesALoraAtFullStrength(): void
    {
        $parsed = Images::parseLoras('a red cube <lora:pixel-art>');

        self::assertSame('a red cube', $parsed['prompt']);
        self::assertSame([['path' => 'pixel-art', 'multiplier' => 1.0]], $parsed['loras']);
    }

    public function testAWeightIsRead(): void
    {
        $parsed = Images::parseLoras('a portrait <lora:blend:0.65>');

        self::assertSame('a portrait', $parsed['prompt']);
        self::assertSame(0.65, $parsed['loras'][0]['multiplier']);
    }

    public function testSeveralLorasInOnePrompt(): void
    {
        $parsed = Images::parseLoras('a castle <lora:style:0.5> on a hill <lora:detail:1.2>');

        self::assertSame('a castle on a hill', $parsed['prompt']);
        self::assertCount(2, $parsed['loras']);
        self::assertSame('style', $parsed['loras'][0]['path']);
        self::assertSame('detail', $parsed['loras'][1]['path']);
    }

    public function testTheSameLoraTwiceIsOneLora(): void
    {
        $parsed = Images::parseLoras('a cube <lora:style:0.5> <lora:style:0.9>');

        self::assertCount(1, $parsed['loras']);
        self::assertSame(0.5, $parsed['loras'][0]['multiplier'], 'the first one wins');
    }

    public function testAWeightIsClampedRatherThanTrusted(): void
    {
        self::assertSame(2.0, Images::parseLoras('x <lora:a:9>')['loras'][0]['multiplier']);
        self::assertSame(0.0, Images::parseLoras('x <lora:a:-3>')['loras'][0]['multiplier']);
    }

    public function testATagWithNoNameIsDropped(): void
    {
        $parsed = Images::parseLoras('a cube <lora:>');

        self::assertSame('a cube', $parsed['prompt']);
        self::assertSame([], $parsed['loras']);
    }

    public function testAPromptWithNoLoraIsLeftAlone(): void
    {
        $parsed = Images::parseLoras('a red cube on a wooden table');

        self::assertSame('a red cube on a wooden table', $parsed['prompt']);
        self::assertSame([], $parsed['loras']);
    }

    public function testItDescribesWhatWasAskedFor(): void
    {
        $described = Images::describeLoras([
            ['path' => 'style', 'multiplier' => 0.5],
            ['path' => 'detail', 'multiplier' => 1.0],
        ]);

        self::assertSame('style at 0.50, detail at 1.00', $described);
    }

    public function testANameIsResolvedToTheFileTheEngineCanOpen(): void
    {
        $directory = \sys_get_temp_dir() . '/quiesce-lora-' . \bin2hex(\random_bytes(4));
        \mkdir($directory, 0o755, true);
        \file_put_contents($directory . '/pixel-art.safetensors', 'not really a lora');

        $resolved = Images::resolveLoras([['path' => 'pixel-art', 'multiplier' => 1.0]], $directory);

        // Relative, not absolute: the engine answers an absolute path with
        // "invalid lora path", which is how this was found.
        self::assertSame('pixel-art.safetensors', $resolved[0]['path']);

        @\unlink($directory . '/pixel-art.safetensors');
        @\rmdir($directory);
    }

    public function testANameWithNoFileIsPassedThroughSoTheEngineCanComplain(): void
    {
        $resolved = Images::resolveLoras([['path' => 'not-installed', 'multiplier' => 1.0]], '/nonexistent');

        self::assertSame('not-installed', $resolved[0]['path'], 'a truer answer than inventing a file name');
    }
}
