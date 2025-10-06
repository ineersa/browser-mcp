<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tools\FindTool;
use App\Tools\OpenTool;
use App\Tools\SearchTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BrowserMcpCommandTest extends TestCase
{
    /**
     * @throws \JsonException
     */
    public function testToolsListContainsRegisteredTools(): void
    {
        $responses = $this->runServer([
            $this->initializeRequest(),
            $this->toolsListRequest(),
        ]);

        $this->assertCount(2, $responses, 'Expected initialize and tools/list responses.');

        $initializeResponse = $responses[0];
        $this->assertSame(1, $initializeResponse['id']);
        $this->assertSame('2.0', $initializeResponse['jsonrpc']);

        $toolsResponse = $responses[1];
        $this->assertSame(2, $toolsResponse['id']);
        $this->assertSame('2.0', $toolsResponse['jsonrpc']);
        $this->assertArrayHasKey('tools', $toolsResponse['result']);

        $tools = $toolsResponse['result']['tools'];
        $toolNames = array_map(static fn (array $tool) => $tool['name'], $tools);

        $this->assertSame(
            [SearchTool::NAME, OpenTool::NAME, FindTool::NAME],
            $toolNames,
            'Browser MCP should expose all expected tools.'
        );

        $this->assertToolMetadata($tools);
    }

    /**
     * @throws \JsonException
     */
    public function testSearchToolCallReturnsFixtureDisplay(): void
    {
        $responses = $this->runServer([
            $this->initializeRequest(),
            $this->callToolRequest('search', ['query' => 'SearxNG setup']),
        ]);

        $this->assertCount(2, $responses, 'Expected initialize and tools/call responses.');

        $callResponse = $responses[1];

        $this->assertSame(2, $callResponse['id']);
        $this->assertArrayHasKey('result', $callResponse);

        $content = $callResponse['result']['content'] ?? [];
        $this->assertIsArray($content, 'tools/call response should include content array.');
        $this->assertNotEmpty($content, 'tools/call response content is empty.');

        $first = $content[0];
        $this->assertSame('text', $first['type'] ?? null, 'Expected text content from search tool.');

        $payload = (string) ($first['text'] ?? '');
        $this->assertNotSame('', $payload, 'Search tool payload should not be empty.');

        $expectedResult = $this->loadFixture('search_tool_response')['result'] ?? '';
        $this->assertEquals($expectedResult, $payload);
    }

    /**
     * @throws \JsonException
     */
    public function testOpenToolCallReturnsFixtureDisplay(): void
    {
        $responses = $this->runServer([
            $this->initializeRequest(),
            $this->callToolRequest('search', ['query' => 'SearxNG setup']),
            $this->callToolRequest('open', ['id' => $this->openPageUrl()], 3),
        ]);

        $this->assertCount(3, $responses, 'Expected initialize, search, and open responses.');

        $callResponse = $responses[2];

        $this->assertSame(3, $callResponse['id']);
        $this->assertArrayHasKey('result', $callResponse);

        $content = $callResponse['result']['content'] ?? [];
        $this->assertIsArray($content, 'tools/call response should include content array.');
        $this->assertNotEmpty($content, 'tools/call response content is empty.');

        $payload = (string) ($content[0]['text'] ?? '');
        $this->assertNotSame('', $payload, 'Open tool payload should not be empty.');

        $expectedResult = $this->loadFixture('open_page_response')['result'] ?? '';
        $this->assertEquals($expectedResult, $payload);
    }

    /**
     * @throws \JsonException
     */
    public function testFindToolCallReturnsFixtureDisplay(): void
    {
        $responses = $this->runServer([
            $this->initializeRequest(),
            $this->callToolRequest('search', ['query' => 'SearxNG setup']),
            $this->callToolRequest('open', ['id' => $this->openPageUrl()], 3),
            $this->callToolRequest('find', ['pattern' => 'Datetime'], 4),
        ]);

        $this->assertCount(4, $responses, 'Expected initialize, search, open, and find responses.');

        $callResponse = $responses[3];

        $this->assertSame(4, $callResponse['id']);
        $this->assertArrayHasKey('result', $callResponse);

        $content = $callResponse['result']['content'] ?? [];
        $this->assertIsArray($content, 'tools/call response should include content array.');
        $this->assertNotEmpty($content, 'tools/call response content is empty.');

        $payload = (string) ($content[0]['text'] ?? '');
        $this->assertNotSame('', $payload, 'Find tool payload should not be empty.');

        $expectedResult = $this->loadFixture('find_open_page_response')['result'] ?? '';
        $this->assertEquals($expectedResult, $payload);
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

        $this->assertSame(SearchTool::DESCRIPTION, $indexed[SearchTool::NAME]['description']);
        $this->assertSame(SearchTool::TITLE, $indexed[SearchTool::NAME]['annotations']['title']);
        $this->assertSame(['query'], $indexed[SearchTool::NAME]['inputSchema']['required'] ?? []);

        $this->assertSame(OpenTool::DESCRIPTION, $indexed[OpenTool::NAME]['description']);
        $this->assertSame(OpenTool::TITLE, $indexed[OpenTool::NAME]['annotations']['title']);
        $this->assertArrayHasKey('properties', $indexed[OpenTool::NAME]['inputSchema']);

        $this->assertSame(FindTool::DESCRIPTION, $indexed[FindTool::NAME]['description']);
        $this->assertSame(FindTool::TITLE, $indexed[FindTool::NAME]['annotations']['title']);
        $this->assertArrayHasKey('properties', $indexed[FindTool::NAME]['inputSchema']);
    }

    /**
     * @param list<string> $messages
     *
     * @return list<array<string, mixed>>
     *
     * @throws \JsonException
     */
    private function runServer(array $messages): array
    {
        $process = Process::fromShellCommandline(
            'php bin/browser-mcp',
            \dirname(__DIR__, 2),
            [
                'APP_ENV' => 'test',
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
     *
     * @throws \JsonException
     */
    private function callToolRequest(string $name, array $arguments, int $id = 2): string
    {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
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
        $this->assertNotFalse($contents, 'Failed to read fixture '.$name);

        $decoded = json_decode($contents, true);
        $this->assertIsArray($decoded, 'Fixture '.$name.' is not valid JSON.');

        return $decoded;
    }

    private function openPageUrl(): string
    {
        return 'https://raw.githubusercontent.com/cbracco/html5-test-page/refs/heads/master/index.html';
    }
}
