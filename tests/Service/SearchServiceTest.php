<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Backend\BackendInterface;
use App\Service\BrowserState;
use App\Service\DTO\PageContents;
use App\Service\PageDisplayService;
use App\Service\SearchService;
use App\Service\Utilities;
use PHPUnit\Framework\TestCase;

final class SearchServiceTest extends TestCase
{
    public function testBodyMatchesPythonFixture(): void
    {
        $fixture = $this->loadJson('search_show_page_contents.json');
        $page = $fixture['page'] ?? null;
        $this->assertIsArray($page, 'Invalid fixture: missing page');

        $text = (string) ($page['text'] ?? '');
        $this->assertNotSame('', $text, 'page.text is empty');

        $loc = 0;
        $numLines = -1; // compute via Utilities::getEndLoc like Python

        $lines = Utilities::wrapLines($text);
        while (!empty($lines) && '' === $lines[\count($lines) - 1]) {
            array_pop($lines); // align with SearchService trimming behavior
        }
        $total = \count($lines);
        $endLoc = Utilities::getEndLoc($loc, $numLines, $total, $lines, 1024, 'o200k_base');
        $linesToShow = \array_slice($lines, $loc, $endLoc - $loc);
        $body = Utilities::joinLines($linesToShow, true, $loc);

        $this->assertSame((string) ($fixture['body'] ?? ''), $body, 'body mismatch');
    }

    public function testScrollbarMatchesPythonFixture(): void
    {
        $fixture = $this->loadJson('search_show_page_contents.json');
        $page = $fixture['page'] ?? null;
        $this->assertIsArray($page, 'Invalid fixture: missing page');

        $text = (string) ($page['text'] ?? '');
        $this->assertNotSame('', $text, 'page.text is empty');

        $loc = 0;
        $numLines = -1; // compute via Utilities::getEndLoc

        $lines = Utilities::wrapLines($text);
        while (!empty($lines) && '' === $lines[\count($lines) - 1]) {
            array_pop($lines); // align with SearchService trimming behavior
        }
        $total = \count($lines);
        $endLoc = Utilities::getEndLoc($loc, $numLines, $total, $lines, 1024, 'o200k_base');
        $scrollbar = \sprintf('viewing lines [%d - %d] of %d', $loc, $endLoc - 1, $total - 1);

        $this->assertSame((string) ($fixture['scrollbar'] ?? ''), $scrollbar, 'scrollbar mismatch');
    }

    public function testInvokeMakeDisplayMatchesToolResponse(): void
    {
        $fixture = $this->loadJson('search_show_page_contents.json');
        $page = $fixture['page'] ?? null;
        $this->assertIsArray($page, 'Invalid fixture: missing page');

        $expected = $this->loadJson('search_response.json');
        $expectedResult = (string) ($expected['result'] ?? '');
        $this->assertNotSame('', $expectedResult, 'Invalid fixture: missing result');

        $pageDto = new PageContents(
            url: (string) ($page['url'] ?? ''),
            text: (string) ($page['text'] ?? ''),
            title: (string) ($page['title'] ?? ''),
            urls: (array) ($page['urls'] ?? []),
        );

        // Mock backend->search() to return the fixture page directly
        $backend = $this->createMock(BackendInterface::class);
        $backend->method('search')->willReturn($pageDto);

        $state = new BrowserState();
        $pageDisplay = new PageDisplayService();
        $service = new SearchService($backend, $state, $pageDisplay);

        $query = (string) ($page['title'] ?? '');
        $result = $service($query, 10);

        $this->assertSame($expectedResult, $result, 'Final makeDisplay string mismatch');
    }

    private function getFixturesPath(): string
    {
        return __DIR__.'/../dumps/SearxNG';
    }

    /**
     * @return array<string,mixed>
     */
    private function loadJson(string $filename): array
    {
        $path = $this->getFixturesPath().'/'.$filename;
        $data = file_get_contents($path);
        $this->assertNotFalse($data, 'Failed to read fixture '.$filename);

        $json = json_decode($data, true);
        $this->assertIsArray($json, 'Fixture is not valid JSON: '.$filename);

        return $json;
    }
}
