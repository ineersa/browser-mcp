<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\AppConfig;
use App\Domain\Find\FindDocument;
use App\Domain\Find\FindMatch;
use App\Domain\Find\FindMatchMode;
use App\Domain\Format\FormatPayload;
use App\Domain\Read\ReadDocument;
use App\Domain\Read\ReadRequest;
use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\Formatter\FindResultToArrayFormatter;
use App\Service\Formatter\FormatterChain;
use App\Service\Formatter\ToonFormatter;
use App\Service\Contracts\ReaderContract;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class FindService
{
    public function __construct(
        private AppConfig $config,
        private ReaderContract $reader,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws ToolUsageError
     * @throws BackendError
     */
    public function __invoke(string $url, string $query, FindMatchMode $match = FindMatchMode::CONTAINS, int $contextLines = 5): string
    {
        $canonicalUrl = Utilities::canonicalizeUrl($url);
        if ('' === $canonicalUrl) {
            throw new ToolUsageError('Invalid URL provided.')->setHint('Provide an absolute URL, e.g. `https://example.com/article`.');
        }
        $trimmedQuery = trim($query);
        if ('' === $trimmedQuery) {
            throw new ToolUsageError('Find query cannot be empty.')->setHint('Provide plain text query to search for within the page.');
        }
        $numShowLines = max(1, $contextLines);

        $document = $this->fetchPage($canonicalUrl);
        $findDocument = $this->findInDocument($document, $trimmedQuery, $match, $numShowLines);

        $chain = new FormatterChain();
        $chain
            ->addFormatter(new FindResultToArrayFormatter())
            ->addFormatter(new ToonFormatter());

        $formatted = $chain->format(new FormatPayload(document: $findDocument));

        return $formatted->output;
    }

    /**
     * @throws BackendError
     */
    private function fetchPage(string $url): ReadDocument
    {
        $cacheKey = 'read_document.'.hash('sha256', $url);

        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($url): ReadDocument {
                $item->expiresAfter($this->config->getFindCacheTtlSeconds());

                return $this->reader->read(new ReadRequest(url: $url, canonicalUrl: $url));
            });
        } catch (\Throwable $e) {
            $msg = Utilities::maybeTruncate($e->getMessage());
            throw new BackendError(\sprintf('Error fetching URL `%s`: %s', Utilities::maybeTruncate($url, 256), $msg), previous: $e)->setHint('This may be a network timeout or server error. Try retrying the request or check if the URL is accessible.');
        }
    }

    /**
     * @throws ToolUsageError
     */
    private function findInDocument(ReadDocument $document, string $query, FindMatchMode $match, int $numShowLines): FindDocument
    {
        $lines = Utilities::wrapLines(Utilities::stripLinks($document->markdown));
        $matches = [];
        $lineIdx = 0;
        $matchIdx = 0;
        $maxResults = 50;

        while ($lineIdx < \count($lines)) {
            if (!$this->lineMatches($lines[$lineIdx], $query, $match)) {
                ++$lineIdx;
                continue;
            }

            $snippet = implode("\n", \array_slice($lines, $lineIdx, $numShowLines));
            $matches[] = new FindMatch(index: $matchIdx, lineNumber: $lineIdx, snippet: $snippet);

            if (\count($matches) === $maxResults) {
                break;
            }

            ++$matchIdx;
            $lineIdx += $numShowLines;
        }

        return new FindDocument(
            readDocument: $document,
            query: $query,
            match: $match,
            matches: $matches,
        );
    }

    private function lineMatches(string $line, string $query, FindMatchMode $match): bool
    {
        if (FindMatchMode::EXACT === $match) {
            return str_contains($line, $query);
        }

        return str_contains(
            $this->normalizeContainsValue($line),
            $this->normalizeContainsValue($query),
        );
    }

    private function normalizeContainsValue(string $value): string
    {
        $trimmed = trim($value);
        $withoutEdgePunctuation = (string) preg_replace('/^\p{P}++|\p{P}++$/u', '', $trimmed);
        $normalized = '' !== $withoutEdgePunctuation ? $withoutEdgePunctuation : $trimmed;

        return mb_strtolower($normalized);
    }
}
