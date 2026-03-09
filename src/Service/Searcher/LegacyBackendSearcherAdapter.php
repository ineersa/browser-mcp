<?php

declare(strict_types=1);

namespace App\Service\Searcher;

use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchRequest;
use App\Domain\Search\SearchResultSet;
use App\Service\Backend\BackendInterface;
use App\Service\PageProcessor;

final readonly class LegacyBackendSearcherAdapter implements SearcherInterface
{
    public function __construct(
        private BackendInterface $backend,
        private string $provider = 'legacy',
    ) {
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function search(SearchRequest $request): SearchResultSet
    {
        $page = $this->backend->search($request->query, $request->limit);

        $hits = [];
        foreach ($page->urls as $id => $url) {
            $canonicalUrl = (string) $url;
            $hits[] = new SearchHit(
                id: (string) $id,
                url: $canonicalUrl,
                title: $canonicalUrl,
                sourceDomain: PageProcessor::getDomain($canonicalUrl),
            );
        }

        return new SearchResultSet(
            query: $request->query,
            hits: $hits,
            provider: $this->getProvider(),
            fetchedAt: new \DateTimeImmutable(),
        );
    }
}
