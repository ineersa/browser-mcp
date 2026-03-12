<?php

declare(strict_types=1);

namespace App\Tests\Service\Searcher;

use App\Service\Searcher\SearxNGSearcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SearxNGSearcherTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testSearchBuildsHitsFromResultsFixture(): void
    {
        $fixtures = $this->loadJson('results.json');

        $client = new MockHttpClient(static function () use ($fixtures): MockResponse {
            return new MockResponse(json_encode(['results' => $fixtures], \JSON_THROW_ON_ERROR));
        });
        $searcher = new SearxNGSearcher('http://example.test', $client);

        $result = $searcher->search(new \App\Domain\Search\SearchRequest(query: 'query', limit: 10));

        $this->assertCount(10, $result->hits);
        $this->assertSame('Step by step installation — SearXNG Documentation (2025.9.12+687121d58)', $result->hits[0]->title);
        $this->assertSame('https://docs.searxng.org/admin/installation-searxng.html', $result->hits[0]->url);
        $this->assertNotSame('', $result->hits[0]->snippet);
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
