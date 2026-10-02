<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Models;
use App\Ollama;
use PHPUnit\Framework\TestCase;

/**
 * Sizing a model before downloading it.
 *
 * The registry call itself is not tested here — it needs the network, and it was
 * verified by hand against what is already on disk (the registry said 4.9 GB for
 * qwen3:8b; the disk says 4.9 GB). What is tested is everything around it: which
 * names are refused before any request is made, and what the list offers.
 */
final class ModelsTest extends TestCase
{
    private Models $models;

    protected function setUp(): void
    {
        $this->models = new Models(new Ollama(logPath: '/tmp/quiesce-models.log'));
    }

    public function testItRefusesNamesBeforeAskingAnyone(): void
    {
        foreach (['', '   ', \str_repeat('a', Models::MAX_NAME + 1), 'a name with spaces', 'a;b', '../../etc/passwd'] as $bad) {
            $result = $this->models->lookup($bad);

            self::assertFalse($result['ok'], 'should refuse: ' . $bad);
            self::assertSame('that is not a model name', $result['error']);
        }
    }

    public function testTheOfferIsSmallAndCoversWhatTheAppNeeds(): void
    {
        $names = \array_column($this->models->suggestions(), 'name');

        self::assertLessThanOrEqual(6, \count($names), 'a hub is a different product');
        self::assertContains('moondream', $names, 'vision needs it');
        self::assertContains('nomic-embed-text', $names, 'semantic search needs it');

        foreach ($this->models->suggestions() as $suggestion) {
            self::assertNotSame('', $suggestion['why'], 'every suggestion says why it is there');
        }
    }

    public function testItSizesAModelFromWhatOllamaReports(): void
    {
        self::assertSame(1024, Models::megabytesOf(['size' => 1_073_741_824]));
        self::assertSame(0, Models::megabytesOf([]));
    }

    public function testNothingIsDownloadingToBeginWith(): void
    {
        self::assertFalse($this->models->running());
        self::assertNull($this->models->progress());

        $finished = $this->models->finish();

        self::assertFalse($finished['ok']);
        self::assertStringContainsString('nothing was downloading', $finished['error']);
    }
}
