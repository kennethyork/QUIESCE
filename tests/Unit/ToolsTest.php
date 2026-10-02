<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Settings;
use App\Tools;
use PHPUnit\Framework\TestCase;

/**
 * The tools are the part of this application that can change things on disk and
 * send things off the machine, so the refusals matter more than the successes.
 * Each escape is attempted here rather than trusted to be handled.
 */
final class ToolsTest extends TestCase
{
    private string $root;

    private string $outside;

    private Tools $tools;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/quiesce-tools-' . \bin2hex(\random_bytes(4));
        $this->outside = \sys_get_temp_dir() . '/quiesce-outside-' . \bin2hex(\random_bytes(4));

        \mkdir($this->root . '/notes', 0o755, true);
        \mkdir($this->outside, 0o755, true);

        \file_put_contents($this->root . '/notes.md', "# notes\n\nhello");
        \file_put_contents($this->root . '/notes/inner.txt', 'inner');
        \file_put_contents($this->outside . '/secret.txt', 'do not read me');

        $settings = new Settings($this->root . '/settings.json');
        $this->tools = new Tools($settings);
        $this->tools->setWorkspace($this->root);
        $this->tools->setWeb(false);
    }

    protected function tearDown(): void
    {
        foreach ([$this->root, $this->outside] as $directory) {
            if (!\is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                $item->isDir() && !$item->isLink() ? \rmdir($item->getPathname()) : \unlink($item->getPathname());
            }

            \rmdir($directory);
        }
    }

    public function testItReadsAndListsInsideTheFolder(): void
    {
        $read = $this->tools->run('read_file', ['path' => 'notes.md']);
        self::assertTrue($read['ok']);
        self::assertStringContainsString('hello', $read['output']);

        $list = $this->tools->run('list_files', ['path' => '.']);
        self::assertTrue($list['ok']);
        self::assertStringContainsString('notes.md', $list['output']);
        self::assertStringContainsString('notes/', $list['output']);

        $inner = $this->tools->run('list_files', ['path' => 'notes']);
        self::assertTrue($inner['ok']);
        self::assertStringContainsString('inner.txt', $inner['output']);
    }

    public function testItRefusesToWalkOutOfTheFolder(): void
    {
        foreach (['../quiesce-outside-does-not-matter', '../../etc/passwd', 'notes/../../'] as $escape) {
            $result = $this->tools->run('read_file', ['path' => $escape]);
            self::assertFalse($result['ok'], $escape . ' should be refused');
        }

        $absolute = $this->tools->run('read_file', ['path' => '/etc/passwd']);
        self::assertFalse($absolute['ok']);
        self::assertStringContainsString('refused', $absolute['output']);
    }

    public function testItRefusesASymlinkThatPointsOutside(): void
    {
        \symlink($this->outside . '/secret.txt', $this->root . '/escape.txt');

        $read = $this->tools->run('read_file', ['path' => 'escape.txt']);

        self::assertFalse($read['ok'], 'a symlink out of the folder is not a way out');
        self::assertStringNotContainsString('do not read me', $read['output']);
    }

    public function testItRefusesToWriteThroughASymlinkedDirectory(): void
    {
        \symlink($this->outside, $this->root . '/out');

        $write = $this->tools->run('write_file', ['path' => 'out/invaded.txt', 'content' => 'nope']);

        self::assertFalse($write['ok']);
        self::assertFileDoesNotExist($this->outside . '/invaded.txt');
    }

    public function testItWritesInsideTheFolderAndMakesParentFolders(): void
    {
        $write = $this->tools->run('write_file', ['path' => 'sub/dir/new.txt', 'content' => "written\n"]);

        self::assertTrue($write['ok']);
        self::assertFileExists($this->root . '/sub/dir/new.txt');
        self::assertSame("written\n", \file_get_contents($this->root . '/sub/dir/new.txt'));
    }

    public function testItRefusesToWriteOutside(): void
    {
        $write = $this->tools->run('write_file', ['path' => '../escaped.txt', 'content' => 'nope']);

        self::assertFalse($write['ok']);
        self::assertFileDoesNotExist(\dirname($this->root) . '/escaped.txt');
    }

    public function testItRefusesAnOversizedWrite(): void
    {
        $write = $this->tools->run('write_file', [
            'path' => 'big.txt',
            'content' => \str_repeat('x', Tools::MAX_WRITE + 1),
        ]);

        self::assertFalse($write['ok']);
        self::assertFileDoesNotExist($this->root . '/big.txt');
    }

    public function testItTruncatesALargeRead(): void
    {
        \file_put_contents($this->root . '/large.txt', \str_repeat('y', Tools::MAX_READ * 2));

        $read = $this->tools->run('read_file', ['path' => 'large.txt']);

        self::assertTrue($read['ok']);
        self::assertStringContainsString('truncated', $read['output']);
        self::assertLessThan(Tools::MAX_READ + 200, \strlen($read['output']));
    }

    public function testFileToolsNeedAFolderFirst(): void
    {
        $this->tools->setWorkspace(null);

        $read = $this->tools->run('read_file', ['path' => 'notes.md']);

        self::assertFalse($read['ok']);
        self::assertStringContainsString('no working folder', $read['output']);
    }

    public function testWebToolsAreAbsentWhenTheyAreOff(): void
    {
        $names = \array_column(\array_column($this->tools->definitions(), 'function'), 'name');

        self::assertContains('read_file', $names);
        self::assertNotContains('web_fetch', $names, 'the model is not even offered what it cannot use');

        $run = $this->tools->run('web_fetch', ['url' => 'https://example.com']);
        self::assertFalse($run['ok']);
        self::assertStringContainsString('switched off', $run['output']);
    }

    public function testWebToolsAppearWhenTheyAreOn(): void
    {
        $this->tools->setWeb(true);

        $names = \array_column(\array_column($this->tools->definitions(), 'function'), 'name');

        self::assertContains('web_search', $names);
        self::assertContains('web_fetch', $names);
    }

    public function testItRefusesToFetchTheReadersOwnMachine(): void
    {
        $this->tools->setWeb(true);

        foreach (['http://127.0.0.1:11434/api/tags', 'http://localhost:8080/', 'http://192.168.1.1/'] as $url) {
            $result = $this->tools->run('web_fetch', ['url' => $url]);

            self::assertFalse($result['ok'], $url . ' should be refused');
            self::assertStringContainsString('refused', $result['output']);
        }
    }

    public function testItRefusesSchemesOtherThanHttp(): void
    {
        $this->tools->setWeb(true);

        foreach (['file:///etc/passwd', 'ftp://example.com/x', 'gopher://example.com'] as $url) {
            $result = $this->tools->run('web_fetch', ['url' => $url]);

            self::assertFalse($result['ok'], $url . ' should be refused');
        }
    }

    public function testAPathWithANullByteIsRefused(): void
    {
        $result = $this->tools->run('read_file', ['path' => "notes.md\0.txt"]);

        self::assertFalse($result['ok']);
    }

    public function testHtmlBecomesReadableText(): void
    {
        $html = '<html><head><style>p{color:red}</style><script>alert(1)</script></head>'
            . '<body><h1>Title</h1><p>One &amp; two</p><ul><li>first</li><li>second</li></ul></body></html>';

        $text = Tools::htmlToText($html);

        self::assertStringNotContainsString('alert', $text);
        self::assertStringNotContainsString('color:red', $text);
        self::assertStringNotContainsString('<', $text);
        self::assertStringContainsString('Title', $text);
        self::assertStringContainsString('One & two', $text);
        self::assertStringContainsString('• first', $text);
    }

    public function testTheWorkspaceMustBeARealFolder(): void
    {
        $result = $this->tools->setWorkspace($this->root . '/does-not-exist');

        self::assertFalse($result['ok']);
        self::assertSame($this->root, $this->tools->workspace(), 'the old folder is kept when the new one is bad');
    }
}
