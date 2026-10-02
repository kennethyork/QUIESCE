<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Documents;
use App\Ollama;
use App\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The embedding half of document search: the arithmetic, the store, and — when
 * an embedding model is installed — a real semantic search, because the whole
 * point of the feature is finding a passage that shares no words with the query.
 */
final class EmbeddingsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/quiesce-embed-' . \bin2hex(\random_bytes(4));
        \mkdir($this->root . '/docs', 0o755, true);

        \file_put_contents(
            $this->root . '/docs/thermal.md',
            "The whisper profile holds the card at a 66 C ceiling, so it spends much of its time waiting.\n"
            . "The steady profile allows 74 C and a four second duty cycle.\n",
        );

        \file_put_contents($this->root . '/docs/recipes.md', "Bread needs flour, water, salt and time.\n");
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
        }

        @\rmdir($this->root);
    }

    public function testAVectorSurvivesBeingStored(): void
    {
        $vector = [0.5, -1.25, 3.0, 0.0, 1e-7];

        $packed = Documents::pack($vector);
        $back = Documents::unpack($packed);

        self::assertCount(5, $back);

        foreach ($vector as $index => $value) {
            self::assertEqualsWithDelta($value, $back[$index], 1e-6);
        }

        self::assertNotSame($vector, $packed, 'it is stored as bytes, not as decimals');
    }

    public function testRubbishInTheStoreIsIgnoredRatherThanCrashing(): void
    {
        self::assertSame([], Documents::unpack('not base64 at all'));
        self::assertSame([], Documents::unpack(\base64_encode('three bytes')));
    }

    public function testSimilarityBehaves(): void
    {
        self::assertEqualsWithDelta(1.0, Documents::cosine([1.0, 2.0, 3.0], [1.0, 2.0, 3.0]), 1e-9);
        self::assertEqualsWithDelta(0.0, Documents::cosine([1.0, 0.0], [0.0, 1.0]), 1e-9);
        self::assertEqualsWithDelta(-1.0, Documents::cosine([1.0, 0.0], [-1.0, 0.0]), 1e-9);
        self::assertEqualsWithDelta(0.0, Documents::cosine([], [1.0]), 1e-9);
        self::assertEqualsWithDelta(0.0, Documents::cosine([0.0, 0.0], [1.0, 1.0]), 1e-9);
    }

    public function testWithoutAnEmbeddingModelItSaysKeywordSearch(): void
    {
        $settings = new Settings($this->root . '/settings.json');
        $settings->set('workspace', $this->root);
        $settings->set('embed_model', '');

        // No Ollama client at all: the honest "no model" case.
        $documents = new Documents($settings, null, $this->root . '/embeddings.json');

        self::assertNull($documents->embeddingModel());

        $index = $documents->index();

        self::assertFalse($index['ok'], 'nothing can be embedded without a model');
        self::assertStringContainsString('no embedding model', (string) $index['error']);

        $result = $documents->search('thermal ceiling');

        self::assertTrue($result['ok']);
        self::assertStringContainsString('keyword search', $result['output']);
        self::assertStringContainsString('not semantic search', $result['output']);
    }

    public function testIndexingAndSemanticSearchAgainstTheRealModel(): void
    {
        $ollama = new Ollama(logPath: '/tmp/quiesce-embed.log');
        $settings = new Settings($this->root . '/settings.json');
        $settings->set('workspace', $this->root);

        $documents = new Documents($settings, $ollama, $this->root . '/embeddings.json');
        $model = $documents->embeddingModel();

        if ($model === null) {
            self::markTestSkipped('no embedding model installed (ollama pull nomic-embed-text)');
        }

        $guard = 0;

        while (($index = $documents->index(32))['ok'] && $index['remaining'] > 0 && $guard++ < 20) {
            // Index in the same bounded steps the window uses.
        }

        self::assertTrue($index['ok'], (string) ($index['error'] ?? ''));
        self::assertGreaterThan(0, $documents->indexStatus()['vectors'], 'something got embedded');

        // The interesting case: no word in common with the passage.
        $result = $documents->search('how hot is the card allowed to get', 2);

        self::assertTrue($result['ok']);
        self::assertStringContainsString('semantic search', $result['output']);

        $results = $result['results'] ?? [];

        self::assertNotSame([], $results);
        self::assertSame('docs/thermal.md', $results[0]['path'], 'the ranking is the point: meaning, not wording');
    }
}
