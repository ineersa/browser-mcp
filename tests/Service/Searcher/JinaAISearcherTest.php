<?php

declare(strict_types=1);

namespace App\Tests\Service\Searcher;

use App\Domain\Search\SearchRequest;
use App\Service\Exception\BackendError;
use App\Service\Searcher\JinaAISearcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class JinaAISearcherTest extends TestCase
{
    public function testSearchBuildsHitsFromDataAndFetchesNextPageWhenNeeded(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertContains('Accept: application/json', $options['normalized_headers']['accept'] ?? []);
            self::assertContains('Authorization: Bearer test-token', $options['normalized_headers']['authorization'] ?? []);
            self::assertContains('X-Respond-With: no-content', $options['normalized_headers']['x-respond-with'] ?? []);

            if (str_contains($url, 'page=1')) {
                return new MockResponse(json_encode([
                    'code' => 200,
                    'status' => 200,
                    'data' => [
                        [
                            'title' => 'Jina AI',
                            'url' => 'https://jina.ai/',
                            'description' => 'Your Search Foundation.',
                        ],
                    ],
                ], \JSON_THROW_ON_ERROR));
            }

            self::assertStringContainsString('page=2', $url);

            return new MockResponse(json_encode([
                'code' => 200,
                'status' => 200,
                'data' => [
                    [
                        'title' => 'Reader Repo',
                        'url' => 'https://github.com/jina-ai/reader',
                        'description' => 'Convert any URL to an LLM-friendly input.',
                    ],
                    [
                        'title' => 'Extra result',
                        'url' => 'https://example.com/extra',
                        'description' => 'Should be trimmed when topn is 2.',
                    ],
                ],
            ], \JSON_THROW_ON_ERROR));
        });

        $searcher = new JinaAISearcher('test-token', $client);

        $result = $searcher->search(new SearchRequest(query: 'Jina AI', limit: 2));

        $this->assertSame('jinaai', $result->provider);
        $this->assertCount(2, $result->hits);
        $this->assertSame('https://jina.ai/', $result->hits[0]->url);
        $this->assertSame('Your Search Foundation.', $result->hits[0]->snippet);
    }

    public function testSearchThrowsWhenTokenIsMissing(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{}'));
        $searcher = new JinaAISearcher('', $client);

        $this->expectException(BackendError::class);
        $this->expectExceptionMessage('Jina AI token is not configured.');

        $searcher->search(new SearchRequest(query: 'Jina AI', limit: 3));
    }
}
