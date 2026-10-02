<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Skills;
use PHPUnit\Framework\TestCase;

/**
 * Skills are what the reader writes for the model, so the catalogue has to be
 * cheap (names and descriptions only) and the full text has to arrive only when
 * the model asks for it.
 */
final class SkillsTest extends TestCase
{
    private string $directory;

    private Skills $skills;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/quiesce-skills-' . \bin2hex(\random_bytes(4));
        \mkdir($this->directory . '/house-style', 0o755, true);

        \file_put_contents($this->directory . '/release-notes.md', <<<'MD'
            ---
            description: how to write release notes in this repository
            ---

            # Release notes

            One heading per version, newest first. Never bury a breaking change.
            MD);

        \file_put_contents($this->directory . '/house-style/SKILL.md', <<<'MD'
            House style for prose in this repository.

            Short sentences. No exclamation marks.
            MD);

        $this->skills = new Skills($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->directory . '/*') ?: [] as $path) {
            \is_dir($path) ? @\unlink($path . '/SKILL.md') : null;
            \is_dir($path) ? @\rmdir($path) : @\unlink($path);
        }

        @\rmdir($this->directory);
    }

    public function testItFindsBothShapesOfSkill(): void
    {
        $names = \array_column($this->skills->catalogue(), 'name');

        self::assertSame(['house-style', 'release-notes'], $names);
    }

    public function testTheDescriptionComesFromFrontmatterOrTheFirstLine(): void
    {
        $catalogue = $this->skills->catalogue();

        self::assertSame('how to write release notes in this repository', $catalogue[1]['description']);
        self::assertSame('House style for prose in this repository.', $catalogue[0]['description']);
    }

    public function testReadingASkillReturnsItsWholeText(): void
    {
        $result = $this->skills->read('release-notes');

        self::assertTrue($result['ok']);
        self::assertStringContainsString('Never bury a breaking change', $result['output']);
        self::assertStringContainsString('description:', $result['output'], 'the frontmatter is part of the file');
    }

    public function testAnUnknownSkillListsWhatExistsInstead(): void
    {
        $result = $this->skills->read('nope');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('house-style', $result['output']);
        self::assertStringContainsString('release-notes', $result['output']);
    }

    public function testTheToolDescriptionCarriesTheNamesSoTheModelCanChoose(): void
    {
        $description = $this->skills->toolDescription();

        self::assertStringContainsString('release-notes: how to write release notes', $description);
    }

    public function testAnEmptyFolderSaysSoRatherThanPretending(): void
    {
        $empty = new Skills(\sys_get_temp_dir() . '/quiesce-no-skills-' . \bin2hex(\random_bytes(4)));

        self::assertSame([], $empty->catalogue());
        self::assertStringContainsString('There are none yet', $empty->toolDescription());

        $result = $empty->read('anything');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('no skills', $result['output']);
    }
}
