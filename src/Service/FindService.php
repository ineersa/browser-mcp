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
use App\Service\Contracts\ReaderContract;
use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\Formatter\FindResultToArrayFormatter;
use App\Service\Formatter\FormatterChain;
use App\Service\Formatter\ToonFormatter;
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
                $item->expiresAfter($this->config->getOpenCacheTtlSeconds());

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
        $matchIdx = 0;
        $nextAllowedLine = 0;
        $maxResults = 50;
        $lineStartOffsets = $this->buildLineStartOffsets($lines);
        $searchText = implode("\n", $lines);

        if ('' === $searchText) {
            return new FindDocument(
                readDocument: $document,
                query: $query,
                match: $match,
                matches: $matches,
            );
        }

        if (FindMatchMode::EXACT === $match) {
            $offset = 0;
            $searchQuery = $query;
            if ('' === $searchQuery) {
                return new FindDocument(
                    readDocument: $document,
                    query: $query,
                    match: $match,
                    matches: $matches,
                );
            }

            $searchTextLength = mb_strlen($searchText);

            while ($offset <= $searchTextLength) {
                $position = mb_strpos($searchText, $searchQuery, $offset);
                if (false === $position) {
                    break;
                }

                $lineIdx = $this->lineIndexFromOffset($lineStartOffsets, $position);
                if ($lineIdx < $nextAllowedLine) {
                    $offset = $position + 1;
                    continue;
                }

                $snippet = implode("\n", \array_slice($lines, $lineIdx, $numShowLines));
                $matches[] = new FindMatch(index: $matchIdx, lineNumber: $lineIdx, snippet: $snippet);

                if (\count($matches) === $maxResults) {
                    break;
                }

                ++$matchIdx;
                $nextAllowedLine = $lineIdx + $numShowLines;
                $offset = $position + 1;
            }
        } else {
            $pattern = $this->containsPattern($query);
            if (null !== $pattern) {
                $byteOffset = 0;
                while (1 === preg_match($pattern, $searchText, $matchCapture, \PREG_OFFSET_CAPTURE, $byteOffset)) {
                    $matchByteOffset = $matchCapture[0][1];
                    $lineIdx = $this->lineIndexFromOffset($lineStartOffsets, $this->byteOffsetToCharOffset($searchText, $matchByteOffset));
                    if ($lineIdx < $nextAllowedLine) {
                        $byteOffset = $matchByteOffset + 1;
                        continue;
                    }

                    $snippet = implode("\n", \array_slice($lines, $lineIdx, $numShowLines));
                    $matches[] = new FindMatch(index: $matchIdx, lineNumber: $lineIdx, snippet: $snippet);

                    if (\count($matches) === $maxResults) {
                        break;
                    }

                    ++$matchIdx;
                    $nextAllowedLine = $lineIdx + $numShowLines;
                    $byteOffset = $matchByteOffset + 1;
                }
            }
        }

        return new FindDocument(
            readDocument: $document,
            query: $query,
            match: $match,
            matches: $matches,
        );
    }

    /**
     * @param string[] $lines
     *
     * @return int[]
     */
    private function buildLineStartOffsets(array $lines): array
    {
        $offsets = [];
        $offset = 0;

        foreach ($lines as $line) {
            $offsets[] = $offset;
            $offset += mb_strlen($line) + 1;
        }

        return $offsets;
    }

    /**
     * @param int[] $lineStartOffsets
     */
    private function lineIndexFromOffset(array $lineStartOffsets, int $offset): int
    {
        $left = 0;
        $right = \count($lineStartOffsets) - 1;

        while ($left <= $right) {
            $mid = intdiv($left + $right, 2);
            if ($lineStartOffsets[$mid] <= $offset) {
                $left = $mid + 1;
                continue;
            }

            $right = $mid - 1;
        }

        return max(0, $right);
    }

    private function containsPattern(string $query): ?string
    {
        $tokens = preg_split('/\s+/u', trim($query), -1, \PREG_SPLIT_NO_EMPTY);
        if (!\is_array($tokens) || [] === $tokens) {
            return null;
        }

        $escapedTokens = array_map(static fn (string $token): string => preg_quote($token, '/'), $tokens);

        return '/'.implode('\s+', $escapedTokens).'/iu';
    }

    private function byteOffsetToCharOffset(string $text, int $byteOffset): int
    {
        return mb_strlen(substr($text, 0, $byteOffset));
    }
}
