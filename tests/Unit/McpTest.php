<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Mcp;
use PHPUnit\Framework\TestCase;

/**
 * The MCP client, tested against a real server process rather than a mock: the
 * fixture speaks newline-delimited JSON-RPC, handshakes, lists tools, answers a
 * call, and reports an error for a tool it does not have.
 */
final class McpTest extends TestCase
{
    private string $config;

    private Mcp $mcp;

    protected function setUp(): void
    {
        $this->config = \sys_get_temp_dir() . '/quiesce-mcp-' . \bin2hex(\random_bytes(4)) . '.json';

        $this->writeConfig('echo', \PHP_BINARY, [__DIR__ . '/../fixtures/mcp-echo-server.php']);

        $this->mcp = new Mcp($this->config);
    }

    protected function tearDown(): void
    {
        $this->mcp->shutdown();
        @\unlink($this->config);
    }

    public function testItHandshakesAndListsTools(): void
    {
        $tools = $this->waitForTools();

        self::assertContains('echo', $tools);
        self::assertContains('never-answers', $tools);
    }

    public function testToolsAreOfferedToTheModelWithAServerPrefix(): void
    {
        $this->waitForTools();

        $definitions = $this->mcp->toolDefinitions();
        $names = \array_column(\array_column($definitions, 'function'), 'name');

        self::assertContains('mcp_echo_echo', $names);

        $echo = $definitions[0]['function'];
        self::assertStringContainsString('MCP server "echo"', $echo['description']);
        self::assertArrayHasKey('properties', $echo['parameters'], 'the schema comes through as-is');
    }

    public function testAnEmptySchemaObjectStaysAnObject(): void
    {
        $this->waitForTools();

        $definitions = $this->mcp->toolDefinitions();
        $byName = [];

        foreach ($definitions as $definition) {
            $byName[$definition['function']['name']] = $definition['function']['parameters'];
        }

        // The fixture's second tool declares `properties: {}`. Ollama rejects
        // `properties: []` outright, so this is not cosmetic.
        self::assertSame(
            '{"type":"object","properties":{}}',
            \json_encode($byName['mcp_echo_never_answers']),
        );
    }

    public function testACallComesBackWithItsText(): void
    {
        $this->waitForTools();

        self::assertTrue($this->mcp->owns('mcp_echo_echo'));

        $started = $this->mcp->start('mcp_echo_echo', ['text' => 'from the test']);
        self::assertTrue($started['ok']);

        self::assertSame('echo: from the test', $this->waitForCall());
    }

    public function testANameNoServerOffersIsRefused(): void
    {
        $this->waitForTools();

        self::assertFalse($this->mcp->owns('mcp_nope_nothing'));

        $started = $this->mcp->start('mcp_nope_nothing', []);

        self::assertFalse($started['ok']);
        self::assertStringContainsString('no MCP server offers', (string) $started['error']);
    }

    public function testNoConfigMeansNoServersAndNoTools(): void
    {
        $mcp = new Mcp(\sys_get_temp_dir() . '/quiesce-missing-' . \bin2hex(\random_bytes(4)) . '.json');

        self::assertSame([], $mcp->servers());
        self::assertSame([], $mcp->toolDefinitions());
        self::assertSame([], $mcp->status());
    }

    public function testADeadServerIsReportedRatherThanSwallowed(): void
    {
        $this->writeConfig('broken', '/definitely/not/a/program', []);

        $mcp = new Mcp($this->config);

        for ($i = 0; $i < 5; $i++) {
            $mcp->tick();
            \usleep(50_000);
        }

        $status = $mcp->status();

        self::assertNotSame([], $status);
        self::assertStringContainsString('could not start', $status[0]['error']);
    }

    /** @param list<string> $args */
    private function writeConfig(string $id, string $command, array $args): void
    {
        \file_put_contents($this->config, (string) \json_encode([
            'servers' => [['id' => $id, 'command' => $command, 'args' => $args, 'enabled' => true]],
        ]));
    }

    /** @return list<string> */
    private function waitForTools(float $limit = 10.0): array
    {
        $deadline = \microtime(true) + $limit;

        while (\microtime(true) < $deadline) {
            $this->mcp->tick();

            $status = $this->mcp->status();

            if (($status[0]['stage'] ?? '') === 'ready') {
                return $status[0]['tools'];
            }

            \usleep(20_000);
        }

        self::fail('the fixture server never became ready: ' . \json_encode($this->mcp->status()));
    }

    private function waitForCall(float $limit = 10.0): string
    {
        $deadline = \microtime(true) + $limit;

        while (\microtime(true) < $deadline) {
            $this->mcp->tick();
            $poll = $this->mcp->poll();

            if (!$poll['running']) {
                return $poll['output'];
            }

            \usleep(20_000);
        }

        self::fail('the call never came back');
    }
}
