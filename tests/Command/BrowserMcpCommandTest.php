<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tools\FindTool;
use App\Tools\OpenTool;
use App\Tools\SearchTool;
use JsonException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BrowserMcpCommandTest extends TestCase
{
    public function testToolsListContainsRegisteredTools(): void
    {
        $responses = $this->runServer([
            $this->initializeRequest(),
            $this->toolsListRequest(),
        ]);

        self::assertCount(2, $responses, 'Expected initialize and tools/list responses.');

        $initializeResponse = $responses[0];
        self::assertSame(1, $initializeResponse['id']);
        self::assertSame('2.0', $initializeResponse['jsonrpc']);

        $toolsResponse = $responses[1];
        self::assertSame(2, $toolsResponse['id']);
        self::assertSame('2.0', $toolsResponse['jsonrpc']);
        self::assertArrayHasKey('tools', $toolsResponse['result']);

        $tools = $toolsResponse['result']['tools'];
        $toolNames = array_map(static fn (array $tool) => $tool['name'], $tools);

        self::assertSame(
            [SearchTool::NAME, OpenTool::NAME, FindTool::NAME],
            $toolNames,
            'Browser MCP should expose all expected tools.'
        );

        $this->assertToolMetadata($tools);
    }

    public function testSearchToolCallReturnsFixtureDisplay(): void
    {
        $responses = $this->runServer([
            $this->initializeRequest(),
            $this->callToolRequest('search', ['query' => 'SearxNG setup']),
        ]);

        self::assertCount(2, $responses, 'Expected initialize and tools/call responses.');

        $callResponse = $responses[1];
        self::assertSame(2, $callResponse['id']);
        self::assertArrayHasKey('result', $callResponse);

        $content = $callResponse['result']['content'] ?? [];
        self::assertIsArray($content, 'tools/call response should include content array.');
        self::assertNotEmpty($content, 'tools/call response content is empty.');

        $first = $content[0];
        self::assertSame('text', $first['type'] ?? null, 'Expected text content from search tool.');

        $payload = (string) ($first['text'] ?? '');
        self::assertNotSame('', $payload, 'Search tool payload should not be empty.');

        try {
            $decoded = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            self::fail('Search tool payload is not valid JSON: '.$exception->getMessage());
        }

        $expected = $this->loadFixture('search_response')['result'] ?? '';
        self::assertSame($expected, (string) ($decoded['result'] ?? ''), 'Search tool result mismatch.');
    }

    /**
     * @param array<int, array<string, mixed>> $tools
     */
    private function assertToolMetadata(array $tools): void
    {
        $indexed = [];
        foreach ($tools as $tool) {
            $indexed[$tool['name']] = $tool;
        }

        self::assertSame(SearchTool::DESCRIPTION, $indexed[SearchTool::NAME]['description']);
        self::assertSame(SearchTool::TITLE, $indexed[SearchTool::NAME]['annotations']['title']);
        self::assertSame(['query'], $indexed[SearchTool::NAME]['inputSchema']['required'] ?? []);

        self::assertSame(OpenTool::DESCRIPTION, $indexed[OpenTool::NAME]['description']);
        self::assertSame(OpenTool::TITLE, $indexed[OpenTool::NAME]['annotations']['title']);
        self::assertArrayHasKey('properties', $indexed[OpenTool::NAME]['inputSchema']);

        self::assertSame(FindTool::DESCRIPTION, $indexed[FindTool::NAME]['description']);
        self::assertSame(FindTool::TITLE, $indexed[FindTool::NAME]['annotations']['title']);
        self::assertArrayHasKey('properties', $indexed[FindTool::NAME]['inputSchema']);
    }

    /**
     * @param list<string> $messages
     *
     * @return list<array<string, mixed>>
     */
    private function runServer(array $messages): array
    {
        $process = Process::fromShellCommandline(
            'php bin/browser-mcp',
            \dirname(__DIR__, 2),
            [
                'APP_ENV' => 'test',
                'APP_DEBUG' => '1',
            ],
            null,
            5.0
        );

        $process->setInput(implode("\n", $messages)."\n");
        $process->mustRun();

        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($process->getOutput())))));

        return array_map(static function (string $line): array {
            return json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        }, $lines);
    }

    private function initializeRequest(): string
    {
        return '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test-suite","version":"1.0.0"}}}';
    }

    private function toolsListRequest(): string
    {
        return '{"jsonrpc":"2.0","id":2,"method":"tools/list"}';
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function callToolRequest(string $name, array $arguments): string
    {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ], \JSON_THROW_ON_ERROR);

        return (string) $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(string $name): array
    {
        $path = __DIR__.'/../dumps/SearxNG/'.$name.'.json';
        $contents = file_get_contents($path);
        self::assertNotFalse($contents, 'Failed to read fixture '.$name);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded, 'Fixture '.$name.' is not valid JSON.');

        return $decoded;
    }
}
