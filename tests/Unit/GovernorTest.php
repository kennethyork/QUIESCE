<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Governor;
use PHPUnit\Framework\TestCase;

/**
 * The governor is the one piece of this application whose behaviour is a claim
 * about the world ("this will not let your fans run away"), so it is tested as
 * arithmetic rather than trusted as intent.
 */
final class GovernorTest extends TestCase
{
    public function testSteadyIsTheDefault(): void
    {
        self::assertSame('steady', (new Governor())->preset());
    }

    public function testEveryProfileHasTheKeysTheGuardReads(): void
    {
        foreach (Governor::PRESETS as $id => $preset) {
            foreach (['label', 'note', 'ceiling', 'floor', 'on_ms', 'off_ms', 'max_tps', 'minutes', 'max_duty', 'max_request_seconds', 'num_ctx', 'num_predict', 'num_thread', 'keep_alive'] as $key) {
                self::assertArrayHasKey($key, $preset, $id . ' is missing ' . $key);
            }

            self::assertLessThan($preset['ceiling'], $preset['floor'], $id . ' has no hysteresis');
        }
    }

    public function testOptionsAreCeilingsRatherThanOverrides(): void
    {
        $governor = new Governor();
        $governor->usePreset('whisper');

        $options = $governor->options(['num_ctx' => 32_768, 'num_predict' => 4_096, 'num_thread' => 12, 'temperature' => 0.4]);

        self::assertSame(4_096, $options['num_ctx']);
        self::assertSame(512, $options['num_predict']);
        self::assertSame(4, $options['num_thread']);
        self::assertSame(0.4, $options['temperature'], 'a setting the profile has no opinion about is left alone');
    }

    public function testASmallerAskIsNotInflated(): void
    {
        $governor = new Governor();
        $options = $governor->options(['num_ctx' => 2_048]);

        self::assertSame(2_048, $options['num_ctx']);
    }

    public function testItGoesWhileTheCardIsCool(): void
    {
        $governor = new Governor();

        $gate = $governor->gate(['temp' => 55.0]);

        self::assertTrue($gate['allow']);
        self::assertSame('going', $gate['reason']);
    }

    public function testItHoldsAtTheCeilingAndResumesAtTheFloor(): void
    {
        $governor = new Governor();
        $governor->usePreset('steady');

        $held = $governor->gate(['temp' => 75.0]);
        self::assertFalse($held['allow']);
        self::assertStringContainsString('ceiling', $held['reason']);

        // Still warm: keep holding, even though it is below the ceiling.
        $stillHolding = $governor->gate(['temp' => 70.0]);
        self::assertFalse($stillHolding['allow']);
        self::assertStringContainsString('floor', $stillHolding['reason']);

        $resumed = $governor->gate(['temp' => 64.0]);
        self::assertTrue($resumed['allow']);
    }

    public function testDutyCycleClosesTheWindowAfterTheOnStretch(): void
    {
        $governor = new Governor();
        $governor->usePreset('whisper'); // 2s on, 1.8s off

        $first = $governor->gate(['temp' => 50.0]);
        self::assertTrue($first['allow'], 'the first window is open');

        // The window is wall-clock, because that is what the card feels. Wait out
        // the on-stretch rather than pretending busy time moves it.
        \usleep(2_100_000);

        $second = $governor->gate(['temp' => 50.0]);
        self::assertFalse($second['allow'], 'the off-stretch holds a generation back');
        self::assertStringContainsString('duty cycle', $second['reason']);

        // ...and the window opens again after the off-stretch.
        \usleep(1_900_000);
        self::assertTrue($governor->gate(['temp' => 50.0])['allow']);
    }

    public function testTheProfileCapsATokenPace(): void
    {
        $governor = new Governor();

        $governor->usePreset('whisper');
        self::assertSame(12, $governor->tokensPerSecond());

        $governor->usePreset('fast');
        self::assertSame(0, $governor->tokensPerSecond(), 'fast is uncapped, honestly');
    }

    public function testARequestIsPausedOnceItPassesItsBudget(): void
    {
        $governor = new Governor();
        $governor->usePreset('whisper');

        self::assertTrue($governor->pastRequestBudget(10.0)['allow']);

        $long = $governor->pastRequestBudget(30.0);
        self::assertFalse($long['allow']);
        self::assertStringContainsString('limit', $long['reason']);
    }

    public function testBusyTimeSurvivesOnlyInsideTheWindow(): void
    {
        $governor = new Governor();
        $governor->markBusy(20.0);

        // Twenty seconds busy out of a twenty minute window.
        self::assertEqualsWithDelta(20.0 / 1_200.0, $governor->duty(), 0.001);
    }

    public function testAnUnknownProfileIsRefusedRatherThanGuessed(): void
    {
        $governor = new Governor();

        self::assertFalse($governor->usePreset('turbo'));
        self::assertSame('steady', $governor->preset());
    }
}
