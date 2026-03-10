<?php

declare(strict_types=1);

namespace App\Service\Searcher;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SearcherFactory
{
    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function create(string $provider, string $searcherUrl): SearcherInterface
    {
        return match ($provider) {
            'searxng', 'searx' => new SearxNGSearcher($searcherUrl, $this->httpClient),
            default => throw new \UnhandledMatchError('Unknown search provider'),
        };
    }
}
