<?php

declare(strict_types=1);

namespace App\Service\Searcher;

use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchRequest;
use App\Domain\Search\SearchResultSet;
use App\Service\Exception\BackendError;
use App\Service\PageProcessor;
use App\Service\Utilities;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SearxNGSearcher implements SearcherInterface
{
    public function __construct(
        private string $searxNGUrl,
        private HttpClientInterface $client,
    ) {
    }

    public function getProvider(): string
    {
        return 'searxng';
    }

    public function search(SearchRequest $request): SearchResultSet
    {
        $items = $this->requestSearch($request->query, min($request->limit, 10));

        $hits = [];
        $seen = [];

        foreach ($items as $index => $item) {
            $canonicalUrl = Utilities::canonicalizeUrl((string) $item['url']);
            if ('' === $canonicalUrl || in_array($canonicalUrl, $seen, true)) {
                continue;
            }
            $seen[] = $canonicalUrl;

            $title = trim((string) $item['title']);
            if ('' === $title) {
                $title = $canonicalUrl;
            }

            $hits[] = new SearchHit(
                id: (string) ($index + 1),
                url: $canonicalUrl,
                title: $title,
                snippet: $this->normalizeSummary((string) $item['summary']),
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

    /**
     * @return list<array{title:string,url:string,summary:string}>
     */
    public function requestSearch(string $query, int $topn): array
    {
        $results = $this->fetchSearxResults($query, $topn);
        $items = [];

        foreach ($results as $result) {
            $url = (string) ($result['url'] ?? '');
            if ('' === $url) {
                continue;
            }

            $items[] = [
                'title' => (string) ($result['title'] ?? $url),
                'url' => $url,
                'summary' => (string) ($result['content'] ?? ''),
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string,mixed>>
     */
    protected function fetchSearxResults(string $query, int $topn): array
    {
        try {
            $response = $this->client->request('GET', rtrim($this->searxNGUrl, '/').'/search', [
                'query' => [
                    'q' => $query,
                    'format' => 'json',
                    'categories' => 'general',
                ],
            ]);
            $content = $response->getContent();
        } catch (ClientExceptionInterface|ServerExceptionInterface|RedirectionExceptionInterface|TransportExceptionInterface $e) {
            throw new BackendError(sprintf('HTTP error for %s/search: %s', rtrim($this->searxNGUrl, '/'), Utilities::maybeTruncate($e->getMessage(), 500)), previous: $e);
        }

        $json = json_decode($content, true);
        if (!is_array($json)) {
            if (\JSON_ERROR_NONE !== json_last_error()) {
                throw new BackendError(sprintf('JSON error: %s.', json_last_error_msg()));
            }
            throw new BackendError('Searx response is not JSON');
        }

        $results = $json['results'] ?? [];

        return array_slice($results, 0, $topn);
    }

    private function normalizeSummary(string $summary): string
    {
        $summary = trim($summary);
        if ('' === $summary) {
            return '';
        }

        $summary = html_entity_decode(strip_tags($summary), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $summary = preg_replace('/\s+/u', ' ', $summary) ?? $summary;

        return trim($summary);
    }
}
