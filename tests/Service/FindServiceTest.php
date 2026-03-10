<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BrowserState;
use App\Service\DTO\Extract;
use App\Service\DTO\PageContents;
use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\FindService;
use App\Service\PageDisplayService;
use App\Service\Reader\ReaderInterface;
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
        $state->addPage($page);

        $reader = $this->createMock(ReaderInterface::class);
        $reader->expects($this->never())->method('read');

        $pageDisplay = new PageDisplayService();
        $service = new FindService($reader, $state, $pageDisplay);

        $result = $service->__invoke(url: $page->url, regex: '/configure/i');

        $expected = (string) ($this->loadJson('find_result.json')['result'] ?? '');
        $this->assertSame($expected, $result);
    }

    public function testFindRequiresUrl(): void
    {
        $backend = $this->createStub(ReaderInterface::class);
        $state = new BrowserState();
        $service = new FindService($backend, $state, new PageDisplayService());

        $this->expectException(ToolUsageError::class);
        $service->__invoke(url: '', regex: '/test/');
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

        $backend = $this->createStub(ReaderInterface::class);
        $service = new FindService($backend, $state, new PageDisplayService());

        $this->expectException(ToolUsageError::class);
        $this->expectExceptionMessage('Cannot run `find` on find results page');
        $service->__invoke(url: $searchPage->url, regex: '/anything/');
    }

    /**
     * @throws BackendError
     */
    public function testFindRemovesResultPageWhenDisplayFails(): void
    {
        $page = new PageContents(
            url: 'https://example.com/article',
            text: "First line\nMatch example\nLast line",
            title: 'Article',
            urls: [],
        );
        $state = new BrowserState();

        $backend = $this->createMock(ReaderInterface::class);
        $backend->expects($this->once())->method('read')->willReturn(new \App\Domain\Read\ReadDocument(
            url: $page->url,
            canonicalUrl: $page->url,
            title: $page->title,
            markdown: $page->text,
            references: $page->urls,
            provider: 'searxng',
            fetchedAt: new \DateTimeImmutable(),
        ));

        $resultUrl = $page->url.'/find?regex=%2Fmatch%2F';

        $pageDisplay = $this->createMock(PageDisplayService::class);
        $pageDisplay->expects($this->once())
            ->method('showPage')
            ->willThrowException(new ToolUsageError('cannot render find results'));

        $service = new FindService($backend, $state, $pageDisplay);

        try {
            $service->__invoke(url: $page->url, regex: '/match/');
            $this->fail('FindService should rethrow ToolUsageError from PageDisplayService');
        } catch (ToolUsageError $e) {
            $this->assertSame('cannot render find results', $e->getMessage());
            $this->assertNull($state->getPageByUrl($resultUrl));
            $this->assertNotNull($state->getPageByUrl($page->url));
        }
    }

    public function testFindProvidesNextStepsWhenNoMatches(): void
    {
        $page = new PageContents(
            url: 'https://example.com/page',
            text: "Intro\nMore content",
            title: 'Example Page',
            urls: [],
        );

        $state = new BrowserState();
        $state->addPage($page);

        $backend = $this->createMock(ReaderInterface::class);
        $backend->expects($this->never())->method('read');

        $service = new FindService($backend, $state, new PageDisplayService());

        $output = $service->__invoke($page->url, '/missing/');

        $this->assertStringContainsString('Pattern not found for regex: `/missing/`', $output);
        $this->assertStringContainsString('Next steps:', $output);
        $this->assertStringContainsString('browser.open', $output);
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
