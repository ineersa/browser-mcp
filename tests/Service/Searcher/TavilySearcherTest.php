<?php

declare(strict_types=1);

namespace App\Tests\Service\Searcher;

use App\Domain\Search\SearchRequest;
use App\Domain\Read\ReadDocument;
use App\Service\Exception\BackendError;
use App\Service\Searcher\TavilySearcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TavilySearcherTest extends TestCase
{
    public function testSearchBuildsHitsFromResultsUsingRequestedLimit(): void
    {
        $cache = new ArrayAdapter();

        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.tavily.com/search', $url);
            self::assertContains('Accept: application/json', $options['normalized_headers']['accept'] ?? []);
            self::assertContains('Content-Type: application/json', $options['normalized_headers']['content-type'] ?? []);
            self::assertContains('Authorization: Bearer test-token', $options['normalized_headers']['authorization'] ?? []);

            $payload = json_decode((string) ($options['body'] ?? ''), true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame('Symfony AI mcp', $payload['query'] ?? null);
            self::assertSame('basic', $payload['include_answer'] ?? null);
            self::assertSame('basic', $payload['search_depth'] ?? null);
            self::assertSame('markdown', $payload['include_raw_content'] ?? null);
            self::assertSame(2, $payload['max_results'] ?? null);

            return new MockResponse(json_encode([
                'query' => 'Symfony AI mcp',
                'results' => [
                    [
                        'url' => 'https://symfony.com/blog/kicking-off-the-symfony-ai-initiative',
                        'title' => 'Kicking off the Symfony AI Initiative',
                        'content' => 'Symfony AI integrates AI capabilities into PHP apps.',
                        'raw_content' => "# Raw page\n\nFull markdown body",
                    ],
                    [
                        'url' => 'https://dev.to/gregholmes/build-a-model-context-protocol-mcp-server-for-symfony-58dc',
                        'title' => 'Build a Model Context Protocol (MCP) Server for Symfony',
                        'content' => 'Tutorial for building an MCP server in Symfony.',
                    ],
                ],
            ], \JSON_THROW_ON_ERROR));
        });

        $searcher = new TavilySearcher('test-token', 300, $client, $cache);

        $result = $searcher->search(new SearchRequest(query: 'Symfony AI mcp', limit: 2));

        $this->assertSame('tavily', $result->provider);
        $this->assertCount(2, $result->hits);
        $this->assertSame('https://symfony.com/blog/kicking-off-the-symfony-ai-initiative', $result->hits[0]->url);
        $this->assertSame('Symfony AI integrates AI capabilities into PHP apps.', $result->hits[0]->snippet);

        $cacheKey = 'read_document.'.hash('sha256', 'https://symfony.com/blog/kicking-off-the-symfony-ai-initiative');
        $cachedDocument = $cache->get($cacheKey, static fn () => null);
        $this->assertInstanceOf(ReadDocument::class, $cachedDocument);
        /** @var ReadDocument $cachedDocument */
        $this->assertSame("# Raw page\n\nFull markdown body", $cachedDocument->markdown);
    }

    public function testSearchThrowsWhenTokenIsMissing(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{}'));
        $searcher = new TavilySearcher('', 300, $client, new ArrayAdapter());

        $this->expectException(BackendError::class);
        $this->expectExceptionMessage('Tavily token is not configured.');

        $searcher->search(new SearchRequest(query: 'Symfony AI mcp', limit: 3));
    }
}
