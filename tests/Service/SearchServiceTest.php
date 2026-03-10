<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchResultSet;
use App\Service\BrowserState;
use App\Service\DTO\PageContents;
use App\Service\Searcher\SearcherInterface;
use App\Service\SearchService;
use HelgeSverre\Toon\Toon;
use PHPUnit\Framework\TestCase;

final class SearchServiceTest extends TestCase
{
    public function testInvokeReturnsToonEncodedArrayOutput(): void
    {
        $resultSet = new SearchResultSet(
            query: 'foo',
            hits: [
                new SearchHit(
                    id: '1',
                    url: 'https://example.com/article',
                    title: 'Example',
                    snippet: 'Example summary.',
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

        $service = new SearchService($searcher, $state);

        $expected = Toon::encode([
            [
                'url' => 'https://example.com/article',
                'domain' => 'example.com',
                'title' => 'Example',
                'summary' => 'Example summary.',
            ],
        ]);
        $result = $service('foo', 5);

        $this->assertSame($expected, $result);
        $this->assertTrue($state->isEmpty(), 'Search should reset BrowserState cache.');
    }

    public function testInvokeReturnsEmptyArrayWhenNoHits(): void
    {
        $searcher = $this->createMock(SearcherInterface::class);
        $searcher->expects($this->once())->method('search')->willReturn(
            new SearchResultSet(query: 'query', hits: [], provider: 'searxng', fetchedAt: new \DateTimeImmutable())
        );

        $state = new BrowserState();
        $service = new SearchService($searcher, $state);

        $result = $service('query');

        $this->assertSame(Toon::encode([]), $result);
        $this->assertTrue($state->isEmpty(), 'BrowserState should be empty after search.');
    }
}
