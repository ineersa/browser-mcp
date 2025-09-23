<?php

declare(strict_types=1);

namespace App\Service\Backend;

use App\Service\DTO\PageContents;
use App\Service\Exception\BackendError;
use App\Service\PageProcessor;
use App\Service\Utilities;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SearxNGBackend implements BackendInterface
{
    private string $searxNGUrl;
    private HttpClientInterface $client;

    public function __construct(string $searxNGUrl, HttpClientInterface $httpClient)
    {
        $this->searxNGUrl = rtrim($searxNGUrl, '/');
        $this->client = $httpClient;
    }

    /**
     * @throws BackendError
     */
    public function search(string $query, int $topn): PageContents
    {
        $items = $this->requestSearch($query, $topn);
        $html = $this->buildSearchHtml($items);

        return PageProcessor::processHtml(
            html: $html,
            url: '',
            title: $query,
            displayUrls: true,
        );
    }

    /**
     * Perform SearxNG search HTTP request and return normalized top results.
     *
     * @return list<array{title:string,url:string,summary:string}>
     *
     * @throws BackendError
     */
    public function requestSearch(string $query, int $topn): array
    {
        $results = $this->fetchSearxResults($query, $topn);
        $items = [];
        foreach ($results as $r) {
            $u = (string) ($r['url'] ?? '');
            if ('' === $u) {
                continue;
            }
            $title = (string) ($r['title'] ?? $u);
            $summary = (string) ($r['content'] ?? '');
            // Normalize to a list [title, url, summary] to match fixtures
            $items[] = [
                'title' => $title,
                'url' => $u,
                'summary' => $summary,
            ];
        }

        return $items;
    }

    /**
     * Build a simple HTML list page from normalized search items.
     *
     * @param list<array{title:string,url:string,summary:string}> $items
     */
    public function buildSearchHtml(array $items): string
    {
        $lis = [];
        foreach ($items as $it) {
            $title = (string) $it['title'];
            $url = (string) $it['url'];
            $summary = (string) $it['summary'];
            $lis[] = \sprintf(
                "<li><a href='%s'>%s</a> %s</li>",
                htmlspecialchars($url, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($title, \ENT_NOQUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($summary, \ENT_NOQUOTES | \ENT_SUBSTITUTE, 'UTF-8')
            );
        }

        return "\n<html><body>\n<h1>Search Results</h1>\n<ul>\n".implode('', $lis)."\n</ul>\n</body></html>\n        ";
    }

    public function fetch(string $url): PageContents
    {
        $html = $this->httpGet($url);

        return PageProcessor::processHtml(
            html: $html,
            url: $url,
            title: $url,
            displayUrls: true,
        );
    }

    /**
     * Fetch raw SearxNG results array using Symfony HttpClient with query parameters.
     *
     * @return list<array<string,mixed>>
     *
     * @throws BackendError
     */
    protected function fetchSearxResults(string $query, int $topn): array
    {
        try {
            $response = $this->client->request('GET', $this->searxNGUrl.'/search', [
                'query' => [
                    'q' => $query,
                    'format' => 'json',
                    'categories' => 'general',
                ],
            ]);
            $resp = $response->getContent();
        } catch (ClientExceptionInterface|ServerExceptionInterface|RedirectionExceptionInterface|TransportExceptionInterface $e) {
            throw new BackendError(\sprintf('HTTP error for %s/search: %s', $this->searxNGUrl, Utilities::maybeTruncate($e->getMessage(), 500)), previous: $e);
        }
        $json = json_decode($resp, true);
        if (!\is_array($json)) {
            if (\JSON_ERROR_NONE !== json_last_error()) {
                throw new BackendError(\sprintf('JSON error: %s.', json_last_error_msg()));
            }
            throw new BackendError('Searx response is not JSON');
        }

        $results = $json['results'] ?? [];

        return \array_slice($results, 0, $topn);
    }

    private function httpGet(string $url): string
    {
        try {
            $response = $this->client->request('GET', $url, [
                'max_redirects' => 10,
                'normalize_headers' => true,
            ]);

            // getContent(true) returns content even on 3xx but throws on >=400; we want exceptions for retry
            return $response->getContent();
        } catch (ClientExceptionInterface|ServerExceptionInterface|RedirectionExceptionInterface|TransportExceptionInterface $e) {
            throw new BackendError(\sprintf('HTTP error for %s: %s', $url, Utilities::maybeTruncate($e->getMessage(), 500)), previous: $e);
        }
    }
}
