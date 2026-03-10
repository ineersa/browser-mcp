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
    public function __invoke(string $url, int $start_at_line, int $number_of_lines, bool $fetchAll = false): string
    {
        $trimmedUrl = trim($url);
        $canonicalUrl = Utilities::canonicalizeUrl($trimmedUrl);
        if ('' === $trimmedUrl || '' === $canonicalUrl) {
            throw new ToolUsageError('Invalid URL provided.')->setHint('Provide an absolute URL, e.g. `https://example.com/article`.');
        }

        $startLine = max($start_at_line, 0);
        $numLines = $number_of_lines > 0 ? $number_of_lines : 50;

        $document = $this->openUrl($canonicalUrl);

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
}
