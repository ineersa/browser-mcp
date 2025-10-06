<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BrowserState;
use App\Service\DTO\Extract;
use App\Service\DTO\PageContents;
use App\Service\Exception\ToolUsageError;
use App\Service\FindService;
use App\Service\PageDisplayService;
use PHPUnit\Framework\TestCase;

final class FindServiceTest extends TestCase
{
    public function testFindProducesExpectedResult(): void
    {
        $openFixture = $this->loadJson('new_page_contents.json');
        $pageData = $openFixture['new_page'] ?? [];
        $page = new PageContents(
            url: (string) ($pageData['url'] ?? ''),
            text: (string) ($pageData['text'] ?? ''),
            title: (string) ($pageData['title'] ?? ''),
            urls: (array) ($pageData['urls'] ?? []),
        );

        $state = new BrowserState();
        $state->addPage(new PageContents(url: '', text: 'Search page', title: 'Search', urls: []));
        $state->addPage(new PageContents(url: 'https://example.com/prev1', text: 'Prev 1', title: 'Prev 1', urls: []));
        $state->addPage(new PageContents(url: 'https://example.com/prev2', text: 'Prev 2', title: 'Prev 2', urls: []));
        $state->addPage($page);

        $pageDisplay = new PageDisplayService();
        $service = new FindService($state, $pageDisplay);

        $result = $service->__invoke(pattern: 'configure');

        $expected = (string) ($this->loadJson('find_result.json')['result'] ?? '');
        $this->assertSame($expected, $result);
    }

    public function testFindRequiresPatternOrRegex(): void
    {
        $state = new BrowserState();
        $pageDisplay = new PageDisplayService();
        $service = new FindService($state, $pageDisplay);

        $this->expectException(ToolUsageError::class);
        $service->__invoke();
    }

    public function testFindRejectsPatternAndRegexTogether(): void
    {
        $state = new BrowserState();
        $pageDisplay = new PageDisplayService();
        $service = new FindService($state, $pageDisplay);

        $this->expectException(ToolUsageError::class);
        $service->__invoke(pattern: 'foo', regex: '/foo/');
    }

    public function testFindRejectsPageWithSnippets(): void
    {
        $state = new BrowserState();
        $searchPage = new PageContents(
            url: 'https://example.com/results',
            text: 'Search results',
            title: 'Search results',
            urls: ['0' => 'https://example.com/detail'], // @phpstan-ignore-line
            snippets: ['0' => new Extract('https://example.com/detail', 'snippet', '#0', null)], // @phpstan-ignore-line
        );
        $state->addPage($searchPage);

        $service = new FindService($state, new PageDisplayService());

        $this->expectException(ToolUsageError::class);
        $this->expectExceptionMessage('Cannot run `find` on search results page or find results page');
        $service->__invoke(pattern: 'anything');
    }

    public function testFindRestoresStateWhenDisplayFails(): void
    {
        $page = new PageContents(
            url: 'https://example.com/article',
            text: "First line\nMatch example\nLast line",
            title: 'Article',
            urls: [],
        );
        $state = new BrowserState();
        $pageId = $state->addPage($page);

        $pageDisplay = $this->createMock(PageDisplayService::class);
        $pageDisplay->expects($this->once())
            ->method('showPage')
            ->willThrowException(new ToolUsageError('cannot render find results'));

        $service = new FindService($state, $pageDisplay);

        try {
            $service->__invoke(pattern: 'match');
            $this->fail('FindService should rethrow ToolUsageError from PageDisplayService');
        } catch (ToolUsageError $e) {
            $this->assertSame('cannot render find results', $e->getMessage());
            $this->assertSame($pageId, $state->getCurrentPageId(), 'Find results page should be removed after failure');
            $this->assertSame($page->url, $state->getPage()->url);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function loadJson(string $filename): array
    {
        $path = __DIR__.'/../dumps/SearxNG/'.$filename;
        $raw = file_get_contents($path);
        if (false === $raw) {
            $this->fail('Failed to read fixture '.$filename);
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            $this->fail('Fixture is not valid JSON: '.$filename);
        }

        return $decoded;
    }
}
