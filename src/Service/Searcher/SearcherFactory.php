<?php

declare(strict_types=1);

namespace App\Service\Searcher;

use App\Config\AppConfig;
use App\Service\Contracts\SearcherContract;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SearcherFactory
{
    public function __construct(
        private AppConfig $config,
        private HttpClientInterface $httpClient,
    ) {
    }

    public function create(): SearcherContract
    {
        $provider = $this->config->getSelectedSearcher();
        $providerConfig = $this->config->getSearcherConfig($provider);

        $searcherUrl = trim((string) ($providerConfig['url'] ?? 'http://server:8088'));

        return match ($provider) {
            'searxng', 'searx' => new SearxNGSearcher('' !== $searcherUrl ? $searcherUrl : 'http://server:8088', $this->httpClient),
            'jinaai', 'jina' => new JinaAISearcher(
                token: trim((string) ($providerConfig['token'] ?? '')),
                client: $this->httpClient,
            ),
            'duckduckgo', 'duckduckgolite', 'ddg' => new DuckDuckGoLiteSearcher(
                timeoutSeconds: max(1, (int) ($providerConfig['timeout_seconds'] ?? 5)),
                maxRetries: max(0, (int) ($providerConfig['max_retries'] ?? 1)),
                client: $this->httpClient,
                userAgent: trim((string) ($providerConfig['user_agent'] ?? '')) ?: DuckDuckGoLiteSearcher::DEFAULT_USER_AGENT,
            ),
            default => throw new \UnhandledMatchError('Unknown search provider'),
        };
    }
}
