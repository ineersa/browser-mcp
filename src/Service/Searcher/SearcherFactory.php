<?php

declare(strict_types=1);

namespace App\Service\Searcher;

use App\Config\AppConfig;
use App\Service\Contracts\SearcherContract;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SearcherFactory
{
    public function __construct(
        private AppConfig $config,
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
    ) {
    }

    public function create(): SearcherContract
    {
        $provider = $this->config->getSelectedSearcher();
        $providerConfig = $this->config->getSearcherConfig($provider);

        $searcherUrl = trim((string) ($providerConfig['url'] ?? 'http://server:8088'));
        $duckDuckGoUserAgent = trim((string) ($providerConfig['user_agent'] ?? ''));

        return match ($provider) {
            'searxng', 'searx' => new SearxNGSearcher('' !== $searcherUrl ? $searcherUrl : 'http://server:8088', $this->httpClient),
            'jinaai', 'jina' => new JinaAISearcher(
                token: trim((string) ($providerConfig['token'] ?? '')),
                client: $this->httpClient,
            ),
            'tavily' => new TavilySearcher(
                token: trim((string) ($providerConfig['token'] ?? '')),
                cacheTtlSeconds: $this->config->getOpenCacheTtlSeconds(),
                client: $this->httpClient,
                cache: $this->cache,
            ),
            'duckduckgo', 'duckduckgolite', 'ddg' => new DuckDuckGoLiteSearcher(
                timeoutSeconds: max(1, (int) ($providerConfig['timeout_seconds'] ?? 5)),
                maxRetries: max(0, (int) ($providerConfig['max_retries'] ?? 1)),
                client: $this->httpClient,
                userAgent: '' !== $duckDuckGoUserAgent ? $duckDuckGoUserAgent : DuckDuckGoLiteSearcher::DEFAULT_USER_AGENT,
            ),
            default => throw new \UnhandledMatchError('Unknown search provider'),
        };
    }
}
