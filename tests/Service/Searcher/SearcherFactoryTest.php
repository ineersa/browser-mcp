<?php

declare(strict_types=1);

namespace App\Tests\Service\Searcher;

use App\Config\AppConfig;
use App\Domain\Search\SearchRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SearcherFactoryTest extends TestCase
{
    public function testCreateBuildsJinaAiSearcherWithTokenFromConfig(): void
    {
        $config = new AppConfig([
            'searchers' => [
                'selected' => 'jinaai',
                'providers' => [
                    'jinaai' => [
                        'token' => 'resolved-token-from-config',
                    ],
                ],
            ],
        ]);

        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringStartsWith('https://s.jina.ai/?q=query&page=1', $url);
            self::assertContains('Authorization: Bearer resolved-token-from-config', $options['normalized_headers']['authorization'] ?? []);

            return new MockResponse('{"data": []}');
        });

        $factory = new \App\Service\Searcher\SearcherFactory($config, $client);
        $searcher = $factory->create();

        $result = $searcher->search(new SearchRequest(query: 'query', limit: 3));
        $this->assertSame('jinaai', $result->provider);
    }
}
