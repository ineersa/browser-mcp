<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Config\AppConfig;
use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchResultSet;
use App\Service\Contracts\SearcherContract;
use App\Service\SearchService;
use HelgeSverre\Toon\Toon;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

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

        $service = new SearchService($this->config(), $searcher, new ArrayAdapter());

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

        $service = new SearchService($this->config(), $searcher, new ArrayAdapter());

        $result = $service('query');

        $this->assertSame(Toon::encode([]), $result);
    }

    public function testInvokeCachesSearchResultSetForRepeatedQuery(): void
    {
        $searcher = $this->createMock(SearcherContract::class);
        $searcher->expects($this->exactly(2))->method('getProvider')->willReturn('searxng');
        $searcher->expects($this->once())->method('search')->willReturn(
            new SearchResultSet(query: 'query', hits: [], provider: 'searxng', fetchedAt: new \DateTimeImmutable())
        );

        $service = new SearchService($this->config(), $searcher, new ArrayAdapter());

        $first = $service('query', 5);
        $second = $service('query', 5);

        $this->assertSame($first, $second);
    }

    private function config(): AppConfig
    {
        return new AppConfig([
            'general' => [
                'search_cache_ttl_seconds' => 600,
            ],
        ]);
    }
}
