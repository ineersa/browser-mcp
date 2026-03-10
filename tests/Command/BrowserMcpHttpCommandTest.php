<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tools\FindTool;
use App\Tools\OpenTool;
use App\Tools\SearchTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Integration tests for BrowserMcpCommand in HTTP (Streamable HTTP) transport mode.
 *
 * Spawns the php built-in server using bin/http-worker.php and sends JSON-RPC
 * requests over HTTP, mirroring the SDK's HttpInspectorSnapshotTestCase pattern.
 */
final class BrowserMcpHttpCommandTest extends TestCase
{
    private Process $serverProcess;
    private int $serverPort;

    protected function setUp(): void
    {
        $this->serverPort = 9000 + (getmypid() % 1000);
        $this->startServer();
    }

    protected function tearDown(): void
    {
        $this->stopServer();
    }

    public function testToolsListContainsRegisteredTools(): void
    {
        $output = $this->runInspector(['--method', 'tools/list']);
        $decoded = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('tools', $decoded);
        $toolNames = array_map(static fn (array $tool) => $tool['name'], $decoded['tools']);

        $this->assertSame(
            [SearchTool::NAME, OpenTool::NAME, FindTool::NAME],
            $toolNames,
            'Browser MCP should expose all expected tools over HTTP.'
        );
    }

    public function testSearchToolCallReturnsToonPayload(): void
    {
        $output = $this->runInspector([
            '--method', 'tools/call',
            '--tool-name', 'search',
            '--tool-arg', 'query=SearxNG setup',
        ]);
        $decoded = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $content = $decoded['content'] ?? [];
        $this->assertIsArray($content);
        $this->assertNotEmpty($content);

        $first = $content[0];
        $this->assertSame('text', $first['type'] ?? null);
        $this->assertNotSame('', $first['text'] ?? '');

        $this->assertStringContainsString('[5]{url,domain,title,summary}:', (string) $first['text']);
        $this->assertStringContainsString('https://docs.searxng.org/admin/installation-searxng.html', (string) $first['text']);
    }

    public function testOpenToolWithEmptyUrlReturnsError(): void
    {
        $output = $this->runInspector([
            '--method', 'tools/call',
            '--tool-name', 'open',
            '--tool-arg', 'start_at_line=0',
            '--tool-arg', 'number_of_lines=50',
        ], true);

        $this->assertStringContainsString(
            'Missing required properties: `url`',
            $output
        );
    }

    public function testFindToolWithEmptyQueryReturnsError(): void
    {
        $output = $this->runInspector([
            '--method', 'tools/call',
            '--tool-name', 'find',
            '--tool-arg', 'url=https://example.com',
        ], true);

        $this->assertStringContainsString(
            'Missing required properties: `query`',
            $output
        );
    }

    // -------------------------------------------------------------------------
    // Infrastructure
    // -------------------------------------------------------------------------

    private function startServer(): void
    {
        $projectDir = \dirname(__DIR__, 2);
        $workerScript = $projectDir.'/bin/http-worker.php';

        $env = $_SERVER;
        $env['APP_ENV'] = 'test';
        $env['APP_VAR_DIR'] = sys_get_temp_dir().'/browser-mcp-tests-'.(string) getmypid();
        $env['APP_PROJECT_DIR'] = $projectDir;
        $env['CONFIG_FILE'] = $projectDir.'/tests/Fixtures/config/browser_config.test.yaml';
        foreach ($env as $key => $value) {
            if (!\is_scalar($value)) {
                unset($env[$key]);
            }
        }

        $this->serverProcess = new Process(
            ['php', '-d', 'opcache.enable_cli=0', '-S', \sprintf('127.0.0.1:%d', $this->serverPort), $workerScript],
            $projectDir,
            $env,
        );

        $this->serverProcess->start();

        $timeout = 5;
        $startTime = time();

        while (time() - $startTime < $timeout) {
            if ($this->serverProcess->isRunning() && $this->isServerReady()) {
                return;
            }
            usleep(100_000);
        }

        $this->fail(\sprintf(
            'HTTP worker failed to start on port %d within %d seconds. STDERR: %s',
            $this->serverPort,
            $timeout,
            $this->serverProcess->getErrorOutput()
        ));
    }

    private function stopServer(): void
    {
        if (isset($this->serverProcess)) {
            $this->serverProcess->stop(1, \SIGTERM);
        }
    }

    private function isServerReady(): bool
    {
        $context = stream_context_create([
            'http' => ['timeout' => 1, 'method' => 'GET'],
        ]);

        $result = @file_get_contents(
            \sprintf('http://127.0.0.1:%d', $this->serverPort),
            false,
            $context
        );

        return false !== $result
            || false === str_contains(error_get_last()['message'] ?? '', 'Connection refused');
    }

    /**
     * @param string[] $args
     */
    private function runInspector(array $args, bool $allowFailure = false): string
    {
        $cmd = array_merge([
            'npx',
            '--yes',
            '@modelcontextprotocol/inspector@0.17.2',
            '--cli',
            \sprintf('http://127.0.0.1:%d', $this->serverPort),
            '--transport', 'http',
        ], $args);

        $process = new Process($cmd);
        $process->setTimeout(30);
        $process->run();

        if (!$allowFailure && !$process->isSuccessful()) {
            $this->fail("Inspector failed:\n".$process->getErrorOutput()."\n".$process->getOutput()."\n--- PHP Server Logs ---\n".$this->serverProcess->getErrorOutput());
        }

        // Return combined output if we allow failure, since inspector prints errors to stdout/stderr.
        return $allowFailure ? $process->getOutput()."\n".$process->getErrorOutput() : $process->getOutput();
    }

}
