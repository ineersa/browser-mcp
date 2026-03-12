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

final readonly class DuckDuckGoLiteSearcher implements SearcherContract
{
    private const SEARCH_URL = 'https://lite.duckduckgo.com/lite/';
    public const DEFAULT_USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64; rv:148.0) Gecko/20100101 Firefox/148.0';

    public function __construct(
        private int $timeoutSeconds,
        private int $maxRetries,
        private HttpClientInterface $client,
        private string $userAgent = self::DEFAULT_USER_AGENT,
    ) {
    }

    public function getProvider(): string
    {
        return 'duckduckgo';
    }

    public function search(SearchRequest $request): SearchResultSet
    {
        $html = $this->requestLitePage($request->query);
        if ($this->isAnomalyPage($html)) {
            throw new BackendError('DuckDuckGo Lite returned an anti-bot challenge page. Try another search provider.');
        }

        $items = $this->parseResults($html, min($request->limit, 10));

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

    private function requestLitePage(string $query): string
    {
        $attempts = max(1, $this->maxRetries + 1);
        $lastError = new \RuntimeException('unknown error');

        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            try {
                $response = $this->client->request('GET', self::SEARCH_URL, [
                    'query' => ['q' => $query],
                    'timeout' => (float) max(1, $this->timeoutSeconds),
                    'headers' => [
                        'User-Agent' => $this->userAgent,
                    ],
                ]);

                return $response->getContent();
            } catch (ClientExceptionInterface|ServerExceptionInterface|RedirectionExceptionInterface|TransportExceptionInterface $e) {
                $lastError = $e;
                if ($attempt >= $attempts) {
                    break;
                }
            }
        }

        $message = Utilities::maybeTruncate($lastError->getMessage(), 500);
        throw new BackendError(sprintf('HTTP error for %s: %s', self::SEARCH_URL, $message), previous: $lastError);
    }

    /**
     * @return list<array{title:string,url:string,summary:string}>
     */
    private function parseResults(string $html, int $topn): array
    {
        if ('' === trim($html)) {
            return [];
        }

        $doc = new \DOMDocument();
        @$doc->loadHTML($html);
        $xpath = new \DOMXPath($doc);

        $links = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " result-link ")]');
        if (false === $links) {
            return [];
        }

        $items = [];
        foreach ($links as $linkNode) {
            if (!$linkNode instanceof \DOMElement) {
                continue;
            }

            $href = trim($linkNode->getAttribute('href'));
            $url = $this->extractActualUrl($href);
            if ('' === $url) {
                continue;
            }

            $title = trim($linkNode->textContent ?? '');
            $snippet = $this->extractSnippetForLink($xpath, $linkNode);

            $items[] = [
                'title' => '' !== $title ? $title : $url,
                'url' => $url,
                'summary' => $snippet,
            ];

            if (count($items) >= $topn) {
                break;
            }
        }

        return $items;
    }

    private function extractSnippetForLink(\DOMXPath $xpath, \DOMElement $linkNode): string
    {
        $snippetNode = $xpath->query('./ancestor::tr[1]/following-sibling::tr[1]//td[contains(concat(" ", normalize-space(@class), " "), " result-snippet ")]', $linkNode)->item(0);

        if (!$snippetNode instanceof \DOMNode) {
            return '';
        }

        return trim($snippetNode->textContent ?? '');
    }

    private function extractActualUrl(string $href): string
    {
        if ('' === $href) {
            return '';
        }

        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        }

        $parts = parse_url($href);
        if (false === $parts || !isset($parts['query'])) {
            return $href;
        }

        parse_str($parts['query'], $query);

        return isset($query['uddg']) ? urldecode((string) $query['uddg']) : $href;
    }

    private function isAnomalyPage(string $html): bool
    {
        return str_contains($html, 'duckduckgo.com/anomaly.js') || str_contains($html, 'id="img-form"');
    }
}
