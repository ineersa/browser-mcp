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

final readonly class JinaAISearcher implements SearcherContract
{
    private const BASE_URL = 'https://s.jina.ai';
    private const MAX_PAGES = 10;

    public function __construct(
        private string $token,
        private HttpClientInterface $client,
    ) {
    }

    public function getProvider(): string
    {
        return 'jinaai';
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
            throw new BackendError('Jina AI token is not configured. Set searchers.providers.jinaai.token in browser_config.yaml.');
        }

        $items = [];
        for ($page = 1; $page <= self::MAX_PAGES && count($items) < $topn; ++$page) {
            $results = $this->fetchPage($query, $page);
            if ([] === $results) {
                break;
            }

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
                    'summary' => (string) ($result['description'] ?? $result['content'] ?? ''),
                ];

                if (count($items) >= $topn) {
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * @return list<mixed>
     */
    private function fetchPage(string $query, int $page): array
    {
        try {
            $response = $this->client->request('GET', self::BASE_URL.'/', [
                'query' => [
                    'q' => $query,
                    'page' => $page,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$this->token,
                    'X-Respond-With' => 'no-content',
                ],
            ]);
            $content = $response->getContent();
        } catch (ClientExceptionInterface|ServerExceptionInterface|RedirectionExceptionInterface|TransportExceptionInterface $e) {
            throw new BackendError(sprintf('HTTP error for %s/ (page %d): %s', self::BASE_URL, $page, Utilities::maybeTruncate($e->getMessage(), 500)), previous: $e);
        }

        $json = json_decode($content, true);
        if (!is_array($json)) {
            if (\JSON_ERROR_NONE !== json_last_error()) {
                throw new BackendError(sprintf('JSON error: %s.', json_last_error_msg()));
            }
            throw new BackendError('Jina AI response is not JSON');
        }

        $results = $json['data'] ?? [];
        if (!is_array($results)) {
            throw new BackendError('Jina AI data is not a valid JSON array');
        }

        return $results;
    }
}
