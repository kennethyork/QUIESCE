<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Documents;
use App\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Document search, tested on the things that decide whether an agent's answer is
 * trustworthy: does it find the passage, does it say which file and line, and
 * does it stay out of the directories nobody wants walked.
 */
final class DocumentsTest extends TestCase
{
    private string $root;

    private Documents $documents;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/quiesce-docs-' . \bin2hex(\random_bytes(4));
        \mkdir($this->root . '/src', 0o755, true);
        \mkdir($this->root . '/.git', 0o755, true);
        \mkdir($this->root . '/node_modules', 0o755, true);

        \file_put_contents($this->root . '/notes.md', "Meeting notes\n\nThe fan curve settles at sixty percent.\n");
        \file_put_contents($this->root . '/src/app.php', "<?php\n// the governor paces every generation\n");
        \file_put_contents($this->root . '/.git/secret.md', "the governor should never be found here\n");
        \file_put_contents($this->root . '/node_modules/vendor.md', "the governor should never be found here either\n");
        \file_put_contents($this->root . '/image.png', "not really an image");

        $settings = new Settings($this->root . '/settings.json');
        $settings->set('workspace', $this->root);

        $this->documents = new Documents($settings);
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

    public function testItFindsAPassageAndSaysWhereItIs(): void
    {
        $result = $this->documents->search('governor');

        self::assertTrue($result['ok']);
        self::assertStringContainsString('src/app.php', $result['output']);
        self::assertStringContainsString('paces every generation', $result['output']);
    }

    public function testItFindsProseInMarkdownToo(): void
    {
        $result = $this->documents->search('fan curve');

        self::assertTrue($result['ok']);
        self::assertStringContainsString('notes.md', $result['output']);
    }

    public function testItStaysOutOfTheDirectoriesNobodyWantsWalked(): void
    {
        $result = $this->documents->search('never be found here');

        self::assertTrue($result['ok']);
        self::assertStringNotContainsString('.git', $result['output']);
        self::assertStringNotContainsString('node_modules', $result['output']);
    }

    public function testWordsTogetherRankAboveWordsApart(): void
    {
        \file_put_contents($this->root . '/apart.md', "curve\n\nsome words\n\nfan\n");

        $result = $this->documents->search('fan curve', 2);
        $results = $result['results'] ?? [];

        self::assertNotSame([], $results);
        self::assertSame('notes.md', $results[0]['path'], 'the file where the words are adjacent wins');
    }

    public function testATermThatIsNotThereIsReportedHonestly(): void
    {
        $result = $this->documents->search('photosynthesis');

        self::assertTrue($result['ok']);
        self::assertStringContainsString('nothing in the working folder matches', $result['output']);
    }

    public function testItSaysThatItIsKeywordSearchAndHowMuchItRead(): void
    {
        $result = $this->documents->search('governor');

        self::assertStringContainsString('not semantic search', $result['output']);
    }

    public function testStatsCountWhatCanBeRead(): void
    {
        $stats = $this->documents->stats();

        self::assertSame(2, $stats['files'], 'notes.md and src/app.php — nothing in .git, nothing in node_modules, no png');
        self::assertContains('md', $stats['readable']);
        self::assertContains('php', $stats['readable']);
        self::assertNotContains('png', $stats['readable']);
    }

    public function testNoFolderMeansNoSearch(): void
    {
        $documents = new Documents(new Settings($this->root . '/empty.json'));

        $result = $documents->search('anything');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('no working folder', $result['output']);
    }

    public function testAnEmptyQueryIsRefused(): void
    {
        $result = $this->documents->search('   !  ');

        self::assertFalse($result['ok']);
    }
}
