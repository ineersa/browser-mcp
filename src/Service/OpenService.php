<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\AppConfig;
use App\Domain\Format\FormatPayload;
use App\Domain\Read\ReadDocument;
use App\Domain\Read\ReadRequest;
use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\Formatter\FormatterChain;
use App\Service\Formatter\LinedOutputFormatter;
use App\Service\Formatter\NumLinesFormatter;
use App\Service\Contracts\ReaderContract;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class OpenService
{
    public function __construct(
        private AppConfig $config,
        private ReaderContract $reader,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws BackendError
     * @throws ToolUsageError
     */
    public function __invoke(string $url, ?int $startAtLine = null, int $numberOfLines = 50, bool $fetchAll = false): string
    {
        $trimmedUrl = trim($url);
        $canonicalUrl = Utilities::canonicalizeUrl($trimmedUrl);
        if ('' === $trimmedUrl || '' === $canonicalUrl) {
            throw new ToolUsageError('Invalid URL provided.')->setHint('Provide an absolute URL, e.g. `https://example.com/article`.');
        }

        if (null !== $startAtLine && $startAtLine < 0) {
            throw new ToolUsageError('`startAtLine` must be zero or greater.')->setHint('Use 0 for the top of the page.');
        }

        $startLine = max($startAtLine ?? 0, 0);
        $numLines = $numberOfLines > 0 ? $numberOfLines : 50;

        $document = $this->openUrl($canonicalUrl);

        if (!$fetchAll && null === $startAtLine) {
            [$startLine, $numLines] = $this->resolveAutoWindow($canonicalUrl, $document);
        }

        $chain = new FormatterChain();
        $chain
            ->addFormatter(new NumLinesFormatter($startLine, $numLines, $fetchAll))
            ->addFormatter(new LinedOutputFormatter());

        $formatted = $chain->format(new FormatPayload(document: $document));

        return $formatted->output;
    }

    /**
     * @throws BackendError
     */
    private function openUrl(string $url): ReadDocument
    {
        $cacheKey = 'read_document.'.hash('sha256', $url);

        try {
            $document = $this->cache->get($cacheKey, function (ItemInterface $item) use ($url): ReadDocument {
                $item->expiresAfter($this->config->getOpenCacheTtlSeconds());

                return $this->reader->read(new ReadRequest(url: $url, canonicalUrl: $url));
            });
        } catch (\Throwable $e) {
            $msg = Utilities::maybeTruncate($e->getMessage());
            throw new BackendError(\sprintf('Error fetching URL `%s`: %s', Utilities::maybeTruncate($url, 256), $msg), previous: $e)->setHint('This may be a network timeout, server error, or the URL may be inaccessible. Try retrying the request or check if the URL is valid and accessible.');
        }

        return $document;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function resolveAutoWindow(string $canonicalUrl, ReadDocument $document): array
    {
        $snippets = $this->getSearchSnippetsForUrl($canonicalUrl);
        if ([] === $snippets) {
            return [0, 100];
        }

        $line = $this->locateBestSnippetLine($document->markdown, $snippets);
        if (null === $line) {
            return [0, 100];
        }

        return [max(0, $line - 10), 51];
    }

    /**
     * @return list<string>
     */
    private function getSearchSnippetsForUrl(string $canonicalUrl): array
    {
        $cacheKey = 'search_snippets.'.hash('sha256', $canonicalUrl);

        try {
            $value = $this->cache->get($cacheKey, static fn (): array => []);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $snippet): bool => is_string($snippet) && '' !== trim($snippet)));
    }

    /**
     * @param list<string> $snippets
     */
    private function locateBestSnippetLine(string $markdown, array $snippets): ?int
    {
        $lines = Utilities::wrapLines($markdown);
        if ([] === $lines) {
            return null;
        }

        $bestLine = null;
        $bestScore = 0;

        foreach ($snippets as $snippet) {
            $line = $this->locateSnippetLine($lines, $snippet);
            if (null !== $line) {
                return $line;
            }

            $scored = $this->scoreSnippetAgainstLines($lines, $snippet);
            if (null === $scored) {
                continue;
            }

            if ($scored['score'] > $bestScore) {
                $bestScore = $scored['score'];
                $bestLine = $scored['line'];
            }
        }

        return $bestScore > 0 ? $bestLine : null;
    }

    /**
     * @param list<string> $lines
     */
    private function locateSnippetLine(array $lines, string $snippet): ?int
    {
        $needle = $this->normalizeForMatch($snippet);
        if ('' === $needle) {
            return null;
        }

        foreach ($lines as $index => $line) {
            if (str_contains($this->normalizeForMatch($line), $needle)) {
                return $index;
            }
        }

        $maxWindow = min(12, max(2, (int) ceil(mb_strlen($needle) / 180)));
        $lineCount = count($lines);

        for ($start = 0; $start < $lineCount; ++$start) {
            for ($length = 2; $length <= $maxWindow && ($start + $length) <= $lineCount; ++$length) {
                $windowText = implode(' ', array_slice($lines, $start, $length));
                if (str_contains($this->normalizeForMatch($windowText), $needle)) {
                    return $start;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $lines
     *
     * @return array{line:int,score:int}|null
     */
    private function scoreSnippetAgainstLines(array $lines, string $snippet): ?array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($snippet), $matches);
        $tokens = array_values(array_unique($matches[0] ?? []));
        if ([] === $tokens) {
            return null;
        }

        $bestLine = null;
        $bestScore = 0;

        foreach ($lines as $index => $line) {
            $window = trim(($lines[max(0, $index - 1)] ?? '').' '.$line.' '.($lines[$index + 1] ?? ''));
            $haystack = mb_strtolower($window);
            $score = 0;
            foreach ($tokens as $token) {
                if (str_contains($haystack, $token)) {
                    ++$score;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestLine = $index;
            }
        }

        return $bestScore >= min(3, count($tokens)) && null !== $bestLine
            ? ['line' => max(0, $bestLine - max(1, (int) ceil(count($tokens) / 24))), 'score' => $bestScore]
            : null;
    }

    private function normalizeForMatch(string $value): string
    {
        $normalized = trim(mb_strtolower($value));
        if ('' === $normalized) {
            return '';
        }

        $normalized = str_replace(['...', '…'], ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
