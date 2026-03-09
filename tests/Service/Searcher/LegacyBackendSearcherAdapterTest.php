<?php

declare(strict_types=1);

namespace App\Tests\Service\Searcher;

use App\Domain\Search\SearchRequest;
use App\Service\Backend\BackendInterface;
use App\Service\DTO\PageContents;
use App\Service\Searcher\LegacyBackendSearcherAdapter;
use PHPUnit\Framework\TestCase;

final class LegacyBackendSearcherAdapterTest extends TestCase
{
    public function testSearchMapsLegacyBackendPageToSearchResultSet(): void
    {
        $page = new PageContents(
            url: '',
            text: "Search results for \"query\"\n\n1. Example\n   URL: https://example.com/page",
            title: 'query',
            urls: ['result-1' => 'https://example.com/page'],
        );

        $backend = $this->createMock(BackendInterface::class);
        $backend->expects($this->once())
            ->method('search')
            ->with('query', 5)
            ->willReturn($page);

        $adapter = new LegacyBackendSearcherAdapter($backend, 'searxng');
        $result = $adapter->search(new SearchRequest(query: 'query', limit: 5));

        $this->assertSame('query', $result->query);
        $this->assertSame('searxng', $result->provider);
        $this->assertSame($page->text, $result->renderedText);
        $this->assertSame($page->title, $result->renderedTitle);
        $this->assertSame($page->urls, $result->references);
        $this->assertCount(1, $result->hits);
        $this->assertSame('result-1', $result->hits[0]->id);
        $this->assertSame('https://example.com/page', $result->hits[0]->url);

        $converted = $result->toPageContents();
        $this->assertSame($page->text, $converted->text);
        $this->assertSame($page->title, $converted->title);
        $this->assertSame($page->urls, $converted->urls);
    }
}
