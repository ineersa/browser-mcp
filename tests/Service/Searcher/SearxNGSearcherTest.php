<?php

declare(strict_types=1);

namespace App\Tests\Service\Searcher;

use App\Domain\Format\FormatContext;
use App\Service\Formatter\TextSearchOutputFormatter;
use App\Service\Searcher\SearxNGSearcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SearxNGSearcherTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testRequestSearchBuildsItemsFromResultsFixture(): void
    {
        $fixtures = $this->loadJson('results.json');
        $expectedItems = array_slice($this->normalizeItemsFixture($this->loadJson('items.json')), 0, 10); // @phpstan-ignore-line

        $client = new MockHttpClient(static function () use ($fixtures): MockResponse {
            return new MockResponse(json_encode(['results' => $fixtures], \JSON_THROW_ON_ERROR));
        });
        $searcher = new SearxNGSearcher('http://example.test', $client);

        $items = $searcher->requestSearch('query', 10);
        $normalized = $this->normalizeItemsFixture($items);

        $this->assertSame($expectedItems, $normalized);
    }

    public function testSearchMapsItemsToSearchHitsWithSummaries(): void
    {
        $fixtures = $this->loadJson('results.json');
        $client = new MockHttpClient(static function () use ($fixtures): MockResponse {
            return new MockResponse(json_encode(['results' => $fixtures], \JSON_THROW_ON_ERROR));
        });
        $searcher = new SearxNGSearcher('http://example.test', $client);

        $result = $searcher->search(new \App\Domain\Search\SearchRequest(query: 'SearxNG setup', limit: 5));

        $this->assertSame('SearxNG setup', $result->query);
        $this->assertSame('searxng', $result->provider);
        $this->assertNotEmpty($result->hits);
        $this->assertNotSame('', $result->hits[0]->snippet);

        $page = (new TextSearchOutputFormatter())->format(new FormatContext(tool: 'search', document: $result))->document;
        $this->assertStringContainsString('Search results for "SearxNG setup"', $page->text);
        $this->assertStringContainsString('Summary:', $page->text);
        $this->assertNotEmpty($page->urls);
    }

    /**
     * @param array<int, array<string|int, string>> $raw
     *
     * @return list<array{title:string,url:string,summary:string}>
     */
    private function normalizeItemsFixture(array $raw): array
    {
        $normalized = [];
        foreach ($raw as $item) {
            $normalized[] = [
                'title' => (string) ($item['title'] ?? ($item[0] ?? '')),
                'url' => (string) ($item['url'] ?? ($item[1] ?? '')),
                'summary' => (string) ($item['summary'] ?? ($item[2] ?? '')),
            ];
        }

        return $normalized;
    }

    private function getFixturesPath(): string
    {
        return __DIR__.'/../../dumps/SearxNG';
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
