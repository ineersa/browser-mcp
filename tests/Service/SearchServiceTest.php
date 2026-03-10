<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Domain\Format\FormatContext;
use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchResultSet;
use App\Service\BrowserState;
use App\Service\DTO\PageContents;
use App\Service\Exception\ToolUsageError;
use App\Service\Formatter\LegacyDisplayFormatterPipelineAdapter;
use App\Service\Formatter\TextSearchOutputFormatter;
use App\Service\PageDisplayService;
use App\Service\Searcher\SearcherInterface;
use App\Service\SearchService;
use PHPUnit\Framework\TestCase;

final class SearchServiceTest extends TestCase
{
    public function testInvokeReturnsRenderedStandaloneOutput(): void
    {
        $resultSet = new SearchResultSet(
            query: 'foo',
            hits: [
                new SearchHit(
                    id: '1',
                    url: 'https://example.com/article',
                    title: 'Example',
                    snippet: 'Example summary.',
                    sourceDomain: 'example.com',
                ),
            ],
            provider: 'searxng',
            fetchedAt: new \DateTimeImmutable(),
        );

        $searcher = $this->createStub(SearcherInterface::class);
        $searcher->method('search')->willReturn($resultSet);

        $state = new BrowserState();
        $state->addPage(new PageContents(
            url: 'https://example.com/prev',
            text: 'old',
            title: 'old',
            urls: [],
        ));

        $display = new PageDisplayService();
        $service = new SearchService(
            $searcher,
            $state,
            new TextSearchOutputFormatter(),
            new LegacyDisplayFormatterPipelineAdapter($display),
        );

        $searchText = (new TextSearchOutputFormatter())->format(new FormatContext(tool: 'search', document: $resultSet));
        $expected = $display->renderStandalone($searchText->document, 0, -1);
        $result = $service('foo', 5);

        $this->assertSame($expected, $result);
        $this->assertTrue($state->isEmpty(), 'Search should reset BrowserState cache.');
    }

    public function testInvokeLeavesStateEmptyWhenDisplayFails(): void
    {
        $searcher = $this->createMock(SearcherInterface::class);
        $searcher->expects($this->once())->method('search')->willReturn(
            new SearchResultSet(query: 'query', hits: [], provider: 'searxng', fetchedAt: new \DateTimeImmutable())
        );

        $state = new BrowserState();
        $display = $this->createMock(PageDisplayService::class);
        $display->expects($this->once())
            ->method('renderStandalone')
            ->willThrowException(new ToolUsageError('render failed'));

        $service = new SearchService(
            $searcher,
            $state,
            new TextSearchOutputFormatter(),
            new LegacyDisplayFormatterPipelineAdapter($display),
        );

        try {
            $service('query');
            $this->fail('SearchService should rethrow ToolUsageError from formatter pipeline');
        } catch (ToolUsageError $e) {
            $this->assertSame('render failed', $e->getMessage());
            $this->assertTrue($state->isEmpty(), 'BrowserState should be empty even when rendering fails.');
        }
    }
}
