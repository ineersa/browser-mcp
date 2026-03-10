<?php

declare(strict_types=1);

namespace App\Service\Searcher;

use App\Config\AppConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SearcherFactory
{
    public function __construct(
        private AppConfig $config,
        private HttpClientInterface $httpClient,
    ) {
    }

    public function create(): SearcherInterface
    {
        $provider = $this->config->getSelectedSearcher();
        $providerConfig = $this->config->getSearcherConfig($provider);

        $searcherUrl = trim((string) ($providerConfig['url'] ?? 'http://server:8088'));

        return match ($provider) {
            'searxng', 'searx' => new SearxNGSearcher('' !== $searcherUrl ? $searcherUrl : 'http://server:8088', $this->httpClient),
            default => throw new \UnhandledMatchError('Unknown search provider'),
        };
    }
}
