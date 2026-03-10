<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Domain\Read\ReadDocument;
use App\Domain\Read\ReadRequest;
use App\Service\Contracts\ReaderContract;
use App\Service\DTO\PageContents;
use App\Service\Exception\BackendError;
use App\Service\Reader\Processors\PageProcessor;
use App\Service\Utilities;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HttpReader implements ReaderContract
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly float $timeoutSeconds = 30.0,
        private readonly int $maxRetries = 2,
    ) {
    }

    public function getProvider(): string
    {
        return 'searxng';
    }

    public function read(ReadRequest $request): ReadDocument
    {
        $fetchUrl = '' !== $request->canonicalUrl ? $request->canonicalUrl : $request->url;
        $page = $this->fetch($fetchUrl);

        return new ReadDocument(
            url: $request->url,
            canonicalUrl: '' !== $request->canonicalUrl ? $request->canonicalUrl : $page->url,
            title: $page->title,
            markdown: $page->text,
            references: $page->urls,
            provider: $this->getProvider(),
            fetchedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * @throws BackendError
     */
    private function fetch(string $url): PageContents
    {
        $githubPage = $this->tryFetchGithubContent($url);
        if (null !== $githubPage) {
            return $githubPage;
        }

        $html = $this->fetchHtml($url);

        return PageProcessor::processHtml(
            html: $html,
            url: $url,
            title: $url,
            displayUrls: true,
        );
    }

    protected function fetchHtml(string $url): string
    {
        return $this->httpGet($url);
    }

    /**
     * @throws BackendError
     */
    protected function httpGet(string $url): string
    {
        try {
            $response = $this->client->request('GET', $url, [
                'max_redirects' => 10,
                'timeout' => $this->timeoutSeconds > 0 ? $this->timeoutSeconds : 30.0,
                'max_retries' => max(0, $this->maxRetries),
            ]);

            return $response->getContent();
        } catch (ClientExceptionInterface|ServerExceptionInterface|RedirectionExceptionInterface|TransportExceptionInterface $e) {
            throw new BackendError(sprintf('HTTP error for %s: %s', $url, Utilities::maybeTruncate($e->getMessage(), 500)), previous: $e)->setHint('This may be a network timeout, server error, or the URL may be inaccessible. Try retrying the request or check if the URL is valid and the server is responding.');
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

        return new PageContents(
            url: $url,
            text: $header.$body,
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
        if (!is_array($parts)) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '');
        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => '' !== $segment));
        if (count($segments) < 4) {
            return null;
        }

        $type = strtolower($segments[2]);
        if (!in_array($type, ['blob', 'raw'], true)) {
            return null;
        }

        $owner = rawurlencode($segments[0]);
        $repo = rawurlencode($segments[1]);
        $tailSegments = array_slice($segments, 3);
        $encodedTail = array_map(static fn (string $segment): string => rawurlencode($segment), $tailSegments);

        return [
            'raw_url' => 'https://raw.githubusercontent.com/'.$owner.'/'.$repo.'/'.implode('/', $encodedTail),
            'file_name' => (string) end($tailSegments),
        ];
    }

    private function makeGithubTitle(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ('' === $path) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => '' !== $segment));
        if ('github.com' === $host && isset($segments[2]) && in_array(strtolower($segments[2]), ['blob', 'raw'], true)) {
            $segments = array_merge([$segments[0], $segments[1]], array_slice($segments, 3));
        }

        $decoded = array_map(static fn (string $segment): string => urldecode($segment), $segments);
        $filtered = array_filter($decoded, static fn (string $segment): bool => '' !== trim($segment));

        return empty($filtered) ? null : implode('/', $filtered);
    }

    private function getFileNameFromUrl(string $url): string
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);

        return '' === $path ? '' : (string) basename($path);
    }

    private function isMarkdownFile(string $fileName): bool
    {
        $lower = strtolower($fileName);
        if (in_array($lower, ['readme', 'readme.md', 'readme.markdown'], true)) {
            return true;
        }

        $ext = strtolower((string) pathinfo($lower, \PATHINFO_EXTENSION));

        return '' !== $ext && in_array($ext, ['md', 'markdown', 'mdown', 'mkd', 'mkdn'], true);
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
            'php' => 'php', 'ts' => 'ts', 'tsx' => 'tsx', 'js' => 'js', 'jsx' => 'jsx', 'json' => 'json',
            'py' => 'python', 'rb' => 'ruby', 'go' => 'go', 'rs' => 'rust', 'java' => 'java', 'c' => 'c',
            'h' => 'c', 'hpp' => 'cpp', 'hh' => 'cpp', 'cpp' => 'cpp', 'cc' => 'cpp', 'cxx' => 'cpp',
            'cs' => 'csharp', 'swift' => 'swift', 'kt' => 'kotlin', 'kts' => 'kotlin', 'sh' => 'bash',
            'bash' => 'bash', 'zsh' => 'bash', 'ps1' => 'powershell', 'psm1' => 'powershell', 'sql' => 'sql',
            'yaml' => 'yaml', 'yml' => 'yaml', 'toml' => 'toml', 'ini' => 'ini', 'vue' => 'vue',
            'svelte' => 'svelte', 'xml' => 'xml', 'html' => 'html', 'htm' => 'html', 'css' => 'css',
            'scss' => 'scss', 'less' => 'less', 'lua' => 'lua', 'r' => 'r', 'pl' => 'perl', 'pm' => 'perl',
            'bat' => 'batch', 'groovy' => 'groovy', 'gradle' => 'gradle', 'graphql' => 'graphql', 'lock' => 'json',
        ];

        return $map[$ext] ?? null;
    }
}
