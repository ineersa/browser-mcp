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

    public function testCreateBuildsDuckDuckGoSearcherWithConfigurableUserAgent(): void
    {
        $config = new AppConfig([
            'searchers' => [
                'selected' => 'duckduckgo',
                'providers' => [
                    'duckduckgo' => [
                        'timeout_seconds' => 5,
                        'max_retries' => 1,
                        'user_agent' => 'Mozilla/5.0 (compatible; TestAgent/1.0)',
                    ],
                ],
            ],
        ]);

        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringStartsWith('https://lite.duckduckgo.com/lite/?q=query', $url);
            self::assertSame(5.0, $options['timeout'] ?? null);
            self::assertContains('User-Agent: Mozilla/5.0 (compatible; TestAgent/1.0)', $options['normalized_headers']['user-agent'] ?? []);

            return new MockResponse('<html><body><a class="result-link" href="https://example.com">Example</a></body></html>');
        });

        $factory = new \App\Service\Searcher\SearcherFactory($config, $client);
        $searcher = $factory->create();

        $result = $searcher->search(new SearchRequest(query: 'query', limit: 3));
        $this->assertSame('duckduckgo', $result->provider);
    }
}
