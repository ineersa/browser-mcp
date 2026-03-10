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
    public function testSearchToolCallReturnsToonPayload(): void
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

        $this->assertStringContainsString('[5]{url,domain,title,summary}:', $payload);
        $this->assertStringContainsString('https://docs.searxng.org/admin/installation-searxng.html', $payload);
    }

    /**
     * @throws \JsonException
     */
    public function testOpenToolCallReturnsFixtureDisplay(): void
    {
        $targetUrl = 'https://raw.usercontent.com/cbracco/html5-test-page/refs/heads/master/index.html';

        $responses = $this->runServer([
            $this->initializeRequest(),
            $this->callToolRequest('search', ['query' => 'Test open page']),
            $this->callToolRequest('open', [
                'url' => $targetUrl,
                'start_at_line' => 0,
                'number_of_lines' => 50,
            ], 3),
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
        $targetUrl = 'https://raw.usercontent.com/cbracco/html5-test-page/refs/heads/master/index.html';

        $responses = $this->runServer([
            $this->initializeRequest(),
            $this->callToolRequest('search', ['query' => 'Test open page']),
            $this->callToolRequest('open', [
                'url' => $targetUrl,
                'start_at_line' => 0,
                'number_of_lines' => 50,
            ], 3),
            $this->callToolRequest('find', [
                'url' => $targetUrl,
                'regex' => '/Datetime/i',
            ], 4),
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
     * @throws \JsonException
     */
    public function testOpenToolWithEmptyUrlReturnsError(): void
    {
        $responses = $this->runServer([
            $this->initializeRequest(),
            $this->callToolRequest('open', [
                'url' => '',
                'start_at_line' => 0,
                'number_of_lines' => 50,
            ], 2),
        ]);

        $this->assertCount(2, $responses, 'Expected initialize and open error responses.');

        $callResponse = $responses[1];

        $this->assertTrue($callResponse['result']['isError'] ?? false);
        $this->assertArrayHasKey('content', $callResponse['result'] ?? []);
        $this->assertStringContainsString('Error Message: Invalid URL provided.', $callResponse['result']['content'][0]['text'] ?? '');
    }

    /**
     * @throws \JsonException
     */
    public function testOpenToolWithNegativeStartLineReturnsError(): void
    {
        $responses = $this->runServer([
            $this->initializeRequest(),
            $this->callToolRequest('open', [
                'url' => 'https://example.com',
                'start_at_line' => -5,
                'number_of_lines' => 50,
            ], 2),
        ]);

        $this->assertCount(2, $responses, 'Expected initialize and open error responses.');

        $callResponse = $responses[1];

        $this->assertTrue($callResponse['result']['isError'] ?? false);
        $this->assertArrayHasKey('content', $callResponse['result'] ?? []);
        $this->assertStringContainsString('Error Message: `start_at_line` must be zero or greater.', $callResponse['result']['content'][0]['text'] ?? '');
    }

    /**
     * @throws \JsonException
     */
    public function testOpenToolWithNonPositiveNumberOfLinesReturnsError(): void
    {
        $responses = $this->runServer([
            $this->initializeRequest(),
            $this->callToolRequest('open', [
                'url' => 'https://example.com',
                'start_at_line' => 0,
                'number_of_lines' => 0,
            ], 2),
        ]);

        $this->assertCount(2, $responses, 'Expected initialize and open error responses.');

        $callResponse = $responses[1];

        $this->assertTrue($callResponse['result']['isError'] ?? false);
        $this->assertArrayHasKey('content', $callResponse['result'] ?? []);
        $this->assertStringContainsString('Error Message: `number_of_lines` must be greater than zero when `fetch_all` is false.', $callResponse['result']['content'][0]['text'] ?? '');
    }

    /**
     * @throws \JsonException
     */
    public function testOpenToolWithFetchAllOverridesLineLimit(): void
    {
        $targetUrl = 'https://raw.usercontent.com/cbracco/html5-test-page/refs/heads/master/index.html';

        $responses = $this->runServer([
            $this->initializeRequest(),
            $this->callToolRequest('open', [
                'url' => $targetUrl,
                'start_at_line' => 0,
                'fetch_all' => true,
            ], 2),
        ]);

        $this->assertCount(2, $responses, 'Expected initialize and open responses.');

        $callResponse = $responses[1];

        $this->assertFalse($callResponse['result']['isError'] ?? false);
        $this->assertArrayHasKey('content', $callResponse['result'] ?? []);

        $payload = (string) ($callResponse['result']['content'][0]['text'] ?? '');
        $this->assertNotSame('', $payload, 'Open tool payload should not be empty when fetch_all is enabled.');
        $this->assertStringContainsString('**viewing lines [0 -', $payload);

        $pattern = '/\\*\\*viewing lines \\[(\\d+) - (\\d+)\\] of (\\d+)\\*\\*/';
        $this->assertMatchesRegularExpression($pattern, $payload, 'Expected scrollbar metadata to be present.');
        $match = [];
        preg_match($pattern, $payload, $match);
        $this->assertCount(4, $match);
        $windowSize = ((int) $match[2] - (int) $match[1]) + 1;
        $this->assertGreaterThan(50, $windowSize, 'fetch_all should expand the visible window beyond the default 50 lines.');
    }

    /**
     * @throws \JsonException
     */
    public function testFindToolWithEmptyRegexReturnsError(): void
    {
        $responses = $this->runServer([
            $this->initializeRequest(),
            $this->callToolRequest('find', ['url' => 'https://example.com', 'regex' => ''], 2),
        ]);

        $this->assertCount(2, $responses, 'Expected initialize and find error responses.');

        $callResponse = $responses[1];
        $this->assertTrue($callResponse['result']['isError'] ?? false);
        $this->assertArrayHasKey('content', $callResponse['result'] ?? []);
        $this->assertStringContainsString('Error Message: Invalid regex provided. The FindTool requires a non-empty regex pattern.', $callResponse['result']['content'][0]['text'] ?? '');
    }

    /**
     * @throws \JsonException
     */
    public function testFindToolWithWhitespaceOnlyRegexReturnsError(): void
    {
        $responses = $this->runServer([
            $this->initializeRequest(),
            $this->callToolRequest('find', ['url' => 'https://example.com', 'regex' => '   '], 2),
        ]);

        $this->assertCount(2, $responses, 'Expected initialize and find error responses.');

        $callResponse = $responses[1];
        $this->assertTrue($callResponse['result']['isError'] ?? false);
        $this->assertArrayHasKey('content', $callResponse['result'] ?? []);
        $this->assertStringContainsString('Error Message: Invalid regex provided. The FindTool requires a non-empty regex pattern.', $callResponse['result']['content'][0]['text'] ?? '');
    }

    /**
     * @throws \JsonException
     */
    public function testFindToolWithEmptyUrlReturnsError(): void
    {
        $responses = $this->runServer([
            $this->initializeRequest(),
            $this->callToolRequest('find', ['url' => '', 'regex' => '/test/'], 2),
        ]);

        $this->assertCount(2, $responses, 'Expected initialize and find error responses.');

        $callResponse = $responses[1];
        $this->assertTrue($callResponse['result']['isError'] ?? false);
        $this->assertArrayHasKey('content', $callResponse['result'] ?? []);
        $this->assertStringContainsString('Error Message: Invalid URL provided. The FindTool requires a non-empty URL.', $callResponse['result']['content'][0]['text'] ?? '');
    }

    /**
     * @throws \JsonException
     */
    public function testFindToolWithWhitespaceOnlyUrlReturnsError(): void
    {
        $responses = $this->runServer([
            $this->initializeRequest(),
            $this->callToolRequest('find', ['url' => '   ', 'regex' => '/test/'], 2),
        ]);

        $this->assertCount(2, $responses, 'Expected initialize and find error responses.');

        $callResponse = $responses[1];
        $this->assertTrue($callResponse['result']['isError'] ?? false);
        $this->assertArrayHasKey('content', $callResponse['result'] ?? []);
        $this->assertStringContainsString('Error Message: Invalid URL provided. The FindTool requires a non-empty URL.', $callResponse['result']['content'][0]['text'] ?? '');
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

        $openSchema = $indexed[OpenTool::NAME]['inputSchema'] ?? [];
        $openProperties = $openSchema['properties'] ?? [];
        $this->assertSame(['url', 'start_at_line'], $openSchema['required'] ?? []);
        $this->assertSame(50, $openProperties['number_of_lines']['default'] ?? null);
        $this->assertArrayHasKey('fetch_all', $openProperties);
        $this->assertFalse($openProperties['fetch_all']['default'] ?? true);

        $this->assertSame(FindTool::DESCRIPTION, $indexed[FindTool::NAME]['description']);
        $this->assertSame(FindTool::TITLE, $indexed[FindTool::NAME]['annotations']['title']);
        $this->assertSame(['url', 'regex'], $indexed[FindTool::NAME]['inputSchema']['required'] ?? []);
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
                'MCP_TRANSPORT' => 'stdio',
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
}
