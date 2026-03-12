<?php

declare(strict_types=1);

namespace App\Service\Searcher;

use App\Domain\Read\ReadDocument;
use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchRequest;
use App\Domain\Search\SearchResultSet;
use App\Service\Contracts\SearcherContract;
use App\Service\Exception\BackendError;
use App\Service\Utilities;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class TavilySearcher implements SearcherContract
{
    private const API_URL = 'https://api.tavily.com/search';

    public function __construct(
        private string $token,
        private int $cacheTtlSeconds,
        private HttpClientInterface $client,
        private CacheInterface $cache,
    ) {
    }

    public function getProvider(): string
    {
        return 'tavily';
    }

    public function search(SearchRequest $request): SearchResultSet
    {
        $items = $this->requestSearch($request->query, min($request->limit, 10));

        $hits = [];
        $seen = [];

        foreach ($items as $item) {
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
                id: (string) (count($hits) + 1),
                url: $canonicalUrl,
                title: $title,
                snippet: Utilities::normalizeSummary($item['summary']),
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
        if ('' === trim($this->token)) {
            throw new BackendError('Tavily token is not configured. Set searchers.providers.tavily.token in browser_config.yaml.');
        }

        try {
            $response = $this->client->request('POST', self::API_URL, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$this->token,
                ],
                'json' => [
                    'query' => $query,
                    'include_answer' => 'basic',
                    'search_depth' => 'basic',
                    'include_raw_content' => 'markdown',
                    'max_results' => $topn,
                ],
            ]);
            $content = $response->getContent();
        } catch (ClientExceptionInterface|ServerExceptionInterface|RedirectionExceptionInterface|TransportExceptionInterface $e) {
            throw new BackendError(sprintf('HTTP error for %s: %s', self::API_URL, Utilities::maybeTruncate($e->getMessage(), 500)), previous: $e);
        }

        $json = json_decode($content, true);
        if (!is_array($json)) {
            if (\JSON_ERROR_NONE !== json_last_error()) {
                throw new BackendError(sprintf('JSON error: %s.', json_last_error_msg()));
            }
            throw new BackendError('Tavily response is not JSON');
        }

        $results = $json['results'] ?? [];
        if (!is_array($results)) {
            throw new BackendError('Tavily results are not a valid JSON array');
        }

        $items = [];
        foreach ($results as $result) {
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

            $this->cacheRawContent(
                url: $url,
                title: (string) ($result['title'] ?? $url),
                rawContent: (string) ($result['raw_content'] ?? ''),
            );
        }

        return $items;
    }

    private function cacheRawContent(string $url, string $title, string $rawContent): void
    {
        if ('' === trim($rawContent)) {
            return;
        }

        $canonicalUrl = Utilities::canonicalizeUrl($url);
        $documentUrl = '' !== $canonicalUrl ? $canonicalUrl : $url;
        if ('' === $documentUrl) {
            return;
        }

        $cacheKey = 'read_document.'.hash('sha256', $documentUrl);

        try {
            $this->cache->delete($cacheKey);
            $this->cache->get($cacheKey, function (ItemInterface $item) use ($documentUrl, $title, $rawContent): ReadDocument {
                $item->expiresAfter(max(1, $this->cacheTtlSeconds));

                return new ReadDocument(
                    url: $documentUrl,
                    canonicalUrl: $documentUrl,
                    title: '' !== trim($title) ? trim($title) : $documentUrl,
                    markdown: $rawContent,
                    references: [],
                    provider: $this->getProvider(),
                );
            });
        } catch (\Throwable) {
        }
    }
}
