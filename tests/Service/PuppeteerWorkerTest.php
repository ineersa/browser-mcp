<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PuppeteerWorker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;

final class PuppeteerWorkerTest extends TestCase
{
    public function testFetchesHtmlFromGithub(): void
    {
        $projectDir = \dirname(__DIR__, 2);
        $scriptPath = $projectDir.'/bin/puppeteer-fetch.js';
        if (!is_file($scriptPath)) {
            $this->markTestSkipped('Puppeteer script missing; install or generate bin/puppeteer-fetch.js.');
        }
        if (!is_readable($scriptPath)) {
            $this->markTestSkipped('Puppeteer script not readable.');
        }

        $nodeBinary = (string) ($_ENV['PUPPETEER_NODE_BINARY'] ?? $_SERVER['PUPPETEER_NODE_BINARY'] ?? 'node');
        $finder = new ExecutableFinder();
        $resolvedNode = $finder->find($nodeBinary);
        if (null === $resolvedNode) {
            $this->markTestSkipped('Node.js executable not found (set PUPPETEER_NODE_BINARY).');
        }

        if (false === getenv('PUPPETEER_MODULE_PATH')) {
            $npmRoot = @shell_exec('npm root -g 2>/dev/null');
            if (\is_string($npmRoot) && '' !== trim($npmRoot)) {
                putenv('PUPPETEER_MODULE_PATH='.trim($npmRoot));
            }
        }

        $worker = new PuppeteerWorker($scriptPath, $resolvedNode, 60);

        $html = $worker->fetch('https://github.com/modelcontextprotocol/modelcontextprotocol');

        $this->assertNotEmpty($html, 'Puppeteer returned empty HTML.');

        //                fwrite(fopen('test.html', 'w'), $html);
    }
}
