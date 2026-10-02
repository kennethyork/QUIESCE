<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Advisor;
use App\Governor;
use App\Hardware;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic that decides whether a model will actually run on the card: the
 * weights are only half of it, and the KV cache is the half people forget.
 */
final class AdvisorTest extends TestCase
{
    /** A 30B-class MoE: 48 layers, 4 KV heads, 128-dim heads — qwen3's shape. */
    private function qwen3Info(int $context = 40_960): array
    {
        return [
            'general.architecture' => 'qwen3',
            'qwen3.block_count' => 48,
            'qwen3.attention.head_count' => 32,
            'qwen3.attention.head_count_kv' => 4,
            'qwen3.attention.key_length' => 128,
            'qwen3.embedding_length' => 2_048,
            'qwen3.context_length' => $context,
        ];
    }

    /** @return array{0: Advisor, 1: object} */
    private function advisorWithHardware(int $vramMiB = 24_576): array
    {
        $hardware = new class ($vramMiB) extends Hardware {
            public function __construct(private int $vram) {}

            public function gpu(float $ttl = 1.0): ?array
            {
                return [
                    'available' => true,
                    'temp' => 55.0,
                    'power' => 60.0,
                    'power_limit' => 280.0,
                    'power_default' => 420.0,
                    'util' => 5.0,
                    'vram_used' => 1_000,
                    'vram_total' => $this->vram,
                    'fan' => 0.0,
                    'clock' => 210,
                    'clock_max' => 1_800,
                    'name' => 'Test Card',
                ];
            }
        };

        return [new Advisor(null, $hardware), $hardware];
    }

    public function testItKnowsTheKvCacheFromTheModelsOwnShape(): void
    {
        // 2 × 48 layers × 4 kv heads × 128 dim × 2 bytes = 98,304 bytes per token.
        $expected = 2 * 48 * 4 * 128 * 2;

        $advisor = $this->advisorWithHardware()[0];
        $fit = $advisor->fit('test:model', 18_000_000_000, $this->qwen3Info());

        self::assertSame('qwen3', $fit['architecture']);
        self::assertSame(48, $fit['layers']);
        self::assertSame(4, $fit['kv_heads']);
        self::assertSame($expected * 1_000, $fit['kv_per_1k']);
    }

    public function testAThirtyBModelWithRoomToSpare(): void
    {
        $advisor = $this->advisorWithHardware()[0];

        // 18 GB of weights on a 24 GB card: about 5.1 GB left after overhead.
        $fit = $advisor->fit('qwen3:30b-a3b', 18_556_699_314, $this->qwen3Info());

        self::assertTrue($fit['exact']);
        self::assertGreaterThanOrEqual(40_960, (int) $fit['max_context'], 'this card holds the whole trained window');

        // 48 layers × 4 kv heads × 128 dims: the cache is small enough that 18 GB
        // of weights on a 24 GB card still leaves room for the model's full 40k.
        self::assertStringContainsString('full', (string) $fit['verdict']);
    }

    public function testAModelTooBigForTheCardIsSaidSoPlainly(): void
    {
        $advisor = $this->advisorWithHardware()[0];

        $fit = $advisor->fit('huge:model', 40_000_000_000, $this->qwen3Info());

        self::assertSame(0, $fit['max_context']);
        self::assertStringContainsString('does not fit', (string) $fit['verdict']);
        self::assertStringContainsString('CPU', (string) $fit['verdict']);
    }

    public function testNoCardMeansNoVerdictRatherThanAGuess(): void
    {
        $hardware = new class () extends Hardware {
            public function gpu(float $ttl = 1.0): ?array
            {
                return null;
            }
        };

        $advisor = new Advisor(null, $hardware);
        $fit = $advisor->fit('test:model', 1_000_000_000, $this->qwen3Info());

        self::assertNull($fit['vram']);
        self::assertNull($fit['max_context']);
        self::assertSame('unknown', $fit['verdict']);
    }

    public function testAdviceRanksWhatFitsTheProfileAndSaysWhatItRecommends(): void
    {
        $advisor = $this->advisorWithHardware()[0];
        $governor = new Governor();
        $governor->usePreset('steady');   // 8,192 tokens

        $info = $this->qwen3Info();

        $advice = $advisor->advise([
            ['name' => 'small:8b', 'size' => 5_200_000_000],
            ['name' => 'big:30b', 'size' => 18_556_699_314],
            ['name' => 'enormous:70b', 'size' => 45_000_000_000],
        ], $governor, [], static fn (string $model): array => $info);

        self::assertSame(8_192, $advice['profile_context']);
        self::assertSame('big:30b', $advice['recommendation'], 'the largest model that still fits wins');

        $byName = [];

        foreach ($advice['models'] as $model) {
            $byName[$model['model']] = $model;
        }

        self::assertTrue($byName['small:8b']['fits_profile']);
        self::assertTrue($byName['big:30b']['fits_profile']);
        self::assertFalse($byName['enormous:70b']['fits_profile']);
    }
}
