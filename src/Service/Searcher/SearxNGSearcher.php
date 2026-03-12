<?php

declare(strict_types=1);

namespace App\Service\Searcher;

use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchRequest;
use App\Domain\Search\SearchResultSet;
use App\Service\Contracts\SearcherContract;
use App\Service\Exception\BackendError;
use App\Service\Utilities;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SearxNGSearcher implements SearcherContract
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
            $canonicalUrl = Utilities::canonicalizeUrl($item['url']);
            if ('' === $canonicalUrl || in_array($canonicalUrl, $seen, true)) {
                continue;
            }
            $seen[] = $canonicalUrl;

            $title = trim($item['title']);
            if ('' === $title) {
                $title = $canonicalUrl;
            }

            $hits[] = new SearchHit(
                id: (string) ($index + 1),
                url: $canonicalUrl,
                title: $title,
                snippet: $item['summary'],
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
    private function requestSearch(string $query, int $topn): array
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
        if (!is_array($results)) {
            throw new BackendError('Searx results are not valid JSON array');
        }

        $items = [];
        foreach (array_slice($results, 0, $topn) as $result) {
            if (!is_array($result)) {
                continue;
            }

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
}
