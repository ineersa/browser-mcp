<?php

declare(strict_types=1);

namespace App\Service\Backend;

use App\Service\DTO\PageContents;
use App\Service\Exception\BackendError;
use App\Service\PageProcessor;
use App\Service\PuppeteerWorker;
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
    private ?PuppeteerWorker $puppeteerWorker;
    private bool $usePuppeteer;

    public function __construct(
        string $searxNGUrl,
        HttpClientInterface $httpClient,
        ?PuppeteerWorker $puppeteerWorker = null,
        bool $usePuppeteer = false,
    ) {
        $this->searxNGUrl = rtrim($searxNGUrl, '/');
        $this->client = $httpClient;
        $this->puppeteerWorker = $puppeteerWorker;
        $this->usePuppeteer = $usePuppeteer;
    }

    /**
     * @throws BackendError
     */
    public function search(string $query, int $topn): PageContents
    {
        $items = $this->requestSearch($query, min($topn, 10));

        $lines = [];
        $lines[] = \sprintf('Search results for "%s"', $query);
        $lines[] = '';

        $urls = [];
        $seen = [];

        foreach ($items as $index => $item) {
            $position = $index + 1;
            $rawUrl = (string) $item['url'];
            $canonicalUrl = Utilities::canonicalizeUrl($rawUrl);
            if ('' === $canonicalUrl) {
                continue;
            }
            if (\in_array($canonicalUrl, $seen, true)) {
                continue;
            }
            $seen[] = $canonicalUrl;

            $title = trim((string) $item['title']);
            if ('' === $title) {
                $title = $canonicalUrl;
            }

            $summary = trim((string) $item['summary']);
            if ('' !== $summary) {
                $summary = html_entity_decode(strip_tags($summary), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
                $summary = preg_replace('/\s+/u', ' ', $summary) ?? $summary;
                $summary = trim($summary);
            }

            $domain = PageProcessor::getDomain($canonicalUrl);
            $label = \sprintf('%d. %s', $position, $title);
            if ('' !== $domain) {
                $label .= \sprintf(' — %s', $domain);
            }

            $lines[] = $label;
            $lines[] = '   URL: '.$canonicalUrl;
            if ('' !== $summary) {
                $lines[] = '   Summary: '.$summary;
            }
            $lines[] = '';

            $urls[(string) $position] = $canonicalUrl;
        }

        if (empty($urls)) {
            $lines[] = 'No results found.';
        }

        $text = rtrim(implode("\n", $lines));

        return new PageContents(
            url: '',
            text: $text,
            title: $query,
            urls: $urls,
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
     * @throws BackendError
     */
    public function fetch(string $url): PageContents
    {
        $githubPage = $this->tryFetchGithubContent($url);
        if (null !== $githubPage) {
            return $githubPage;
        }

        $html = null;

        if ($this->usePuppeteer && null !== $this->puppeteerWorker) {
            $html = $this->puppeteerWorker->fetch($url);
        }

        if (null === $html) {
            $html = $this->httpGet($url);
        }

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
            throw new BackendError(\sprintf('HTTP error for %s/search: %s', $this->searxNGUrl, Utilities::maybeTruncate($e->getMessage(), 500)), previous: $e)->setHint('This may be a network connectivity issue or the SearxNG service may be down. Check if the SearxNG service is running and accessible, or try retrying the request.');
        }
        $json = json_decode($resp, true);
        if (!\is_array($json)) {
            if (\JSON_ERROR_NONE !== json_last_error()) {
                throw new BackendError(\sprintf('JSON error: %s.', json_last_error_msg()))->setHint('The SearxNG service returned invalid JSON. This may indicate a service configuration issue or incompatible version. Check the SearxNG service logs and configuration.');
            }
            throw new BackendError('Searx response is not JSON')->setHint('The SearxNG service returned a non-JSON response. This may indicate a service error or incompatible version. Check the SearxNG service status and configuration.');
        }

        $results = $json['results'] ?? [];

        return \array_slice($results, 0, $topn);
    }

    /**
     * @throws BackendError
     */
    private function httpGet(string $url): string
    {
        try {
            $response = $this->client->request('GET', $url, [
                'max_redirects' => 10,
            ]);

            return $response->getContent();
        } catch (ClientExceptionInterface|ServerExceptionInterface|RedirectionExceptionInterface|TransportExceptionInterface $e) {
            throw new BackendError(\sprintf('HTTP error for %s: %s', $url, Utilities::maybeTruncate($e->getMessage(), 500)), previous: $e)->setHint('This may be a network timeout, server error, or the URL may be inaccessible. Try retrying the request or check if the URL is valid and the server is responding.');
        }
    }

    private function tryFetchGithubContent(string $url): ?PageContents
    {
        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));
        if ('github.com' === $host) {
            return $this->fetchGithubBlobAsRaw($url);
        }

        if ('raw.githubusercontent.com' === $host) {
            $rawContent = $this->httpGet($url);

            return $this->makePlainTextPage($rawContent, $url, $this->makeGithubTitle($url));
        }

        return null;
    }

    private function fetchGithubBlobAsRaw(string $url): ?PageContents
    {
        $rawInfo = $this->makeGithubRawUrl($url);
        if (null === $rawInfo) {
            return null;
        }

        $rawUrl = $rawInfo['raw_url'];
        $fileName = $rawInfo['file_name'];

        try {
            $rawContent = $this->httpGet($rawUrl);
        } catch (BackendError) {
            return null;
        }

        return $this->makePlainTextPage($rawContent, $url, $this->makeGithubTitle($rawUrl));
    }

    private function makePlainTextPage(string $content, string $url, ?string $title): PageContents
    {
        $text = Utilities::ensureUtf8($content);
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $normalized = rtrim($normalized, "\n");

        $fileName = $this->getFileNameFromUrl($url);
        $isMarkdown = $this->isMarkdownFile($fileName);
        $language = $isMarkdown ? null : $this->guessLanguageFromFileName($fileName);

        $header = "\nURL: $url\n\n";

        if ($isMarkdown) {
            $body = $normalized;
        } else {
            $fence = '```'.($language ?? '');
            $body = $fence."\n".$normalized."\n```";
        }

        $output = $header.$body;

        return new PageContents(
            url: $url,
            text: $output,
            title: $title ?? $url,
            urls: [],
        );
    }

    /**
     * @return array{raw_url:string,file_name:string}|null
     */
    private function makeGithubRawUrl(string $url): ?array
    {
        $parts = parse_url($url);
        if (!\is_array($parts)) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '');
        $segments = array_values(array_filter(explode('/', $path), static fn ($segment) => '' !== $segment));
        if (\count($segments) < 4) {
            return null;
        }

        $type = strtolower($segments[2]);
        if (!\in_array($type, ['blob', 'raw'], true)) {
            return null;
        }

        $owner = rawurlencode($segments[0]);
        $repo = rawurlencode($segments[1]);
        $tailSegments = \array_slice($segments, 3);
        if (empty($tailSegments)) {
            return null;
        }
        $encodedTail = array_map(static fn (string $segment): string => rawurlencode($segment), $tailSegments);
        $tailPath = implode('/', $encodedTail);

        return [
            'raw_url' => 'https://raw.githubusercontent.com/'.$owner.'/'.$repo.'/'.$tailPath,
            'file_name' => (string) end($tailSegments),
        ];
    }

    private function makeGithubTitle(string $url): ?string
    {
        $parts = parse_url($url);
        if (!\is_array($parts)) {
            return null;
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ('' === $path) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $segments = array_values(array_filter(explode('/', $path), static fn ($segment) => '' !== $segment));
        if ('github.com' === $host && isset($segments[2]) && \in_array(strtolower($segments[2]), ['blob', 'raw'], true)) {
            $segments = array_merge([$segments[0], $segments[1]], \array_slice($segments, 3));
        }

        $decoded = array_map(static fn (string $segment): string => urldecode($segment), $segments);
        $filtered = array_filter($decoded, static fn (string $segment): bool => '' !== trim($segment));

        if (empty($filtered)) {
            return null;
        }

        return implode('/', $filtered);
    }

    private function getFileNameFromUrl(string $url): string
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);
        if ('' === $path) {
            return '';
        }

        return (string) basename($path);
    }

    private function isMarkdownFile(string $fileName): bool
    {
        $lower = strtolower($fileName);
        $markdownNames = ['readme', 'readme.md', 'readme.markdown'];
        if (\in_array($lower, $markdownNames, true)) {
            return true;
        }

        $ext = strtolower((string) pathinfo($lower, \PATHINFO_EXTENSION));
        if ('' === $ext) {
            return false;
        }

        return \in_array($ext, ['md', 'markdown', 'mdown', 'mkd', 'mkdn'], true);
    }

    private function guessLanguageFromFileName(string $fileName): ?string
    {
        $lower = strtolower($fileName);
        $special = [
            'dockerfile' => 'dockerfile',
            'makefile' => 'makefile',
            'cmakelists.txt' => 'cmake',
        ];
        if (isset($special[$lower])) {
            return $special[$lower];
        }

        $ext = strtolower((string) pathinfo($lower, \PATHINFO_EXTENSION));
        if ('' === $ext) {
            return null;
        }

        $map = [
            'php' => 'php',
            'ts' => 'ts',
            'tsx' => 'tsx',
            'js' => 'js',
            'jsx' => 'jsx',
            'json' => 'json',
            'py' => 'python',
            'rb' => 'ruby',
            'go' => 'go',
            'rs' => 'rust',
            'java' => 'java',
            'c' => 'c',
            'h' => 'c',
            'hpp' => 'cpp',
            'hh' => 'cpp',
            'cpp' => 'cpp',
            'cc' => 'cpp',
            'cxx' => 'cpp',
            'cs' => 'csharp',
            'swift' => 'swift',
            'kt' => 'kotlin',
            'kts' => 'kotlin',
            'sh' => 'bash',
            'bash' => 'bash',
            'zsh' => 'bash',
            'ps1' => 'powershell',
            'psm1' => 'powershell',
            'sql' => 'sql',
            'yaml' => 'yaml',
            'yml' => 'yaml',
            'toml' => 'toml',
            'ini' => 'ini',
            'vue' => 'vue',
            'svelte' => 'svelte',
            'xml' => 'xml',
            'html' => 'html',
            'htm' => 'html',
            'css' => 'css',
            'scss' => 'scss',
            'less' => 'less',
            'lua' => 'lua',
            'r' => 'r',
            'pl' => 'perl',
            'pm' => 'perl',
            'bat' => 'batch',
            'groovy' => 'groovy',
            'gradle' => 'gradle',
            'graphql' => 'graphql',
            'lock' => 'json',
        ];

        return $map[$ext] ?? null;
    }
}
