<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchResultSet;
use App\Service\Contracts\SearcherContract;
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

        $searcher = $this->createStub(SearcherContract::class);
        $searcher->method('search')->willReturn($resultSet);

        $service = new SearchService($searcher);

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
    }

    public function testInvokeReturnsEmptyArrayWhenNoHits(): void
    {
        $searcher = $this->createMock(SearcherContract::class);
        $searcher->expects($this->once())->method('search')->willReturn(
            new SearchResultSet(query: 'query', hits: [], provider: 'searxng', fetchedAt: new \DateTimeImmutable())
        );

        $service = new SearchService($searcher);

        $result = $service('query');

        $this->assertSame(Toon::encode([]), $result);
    }
}
