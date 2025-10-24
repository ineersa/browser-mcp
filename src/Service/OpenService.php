<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Backend\BackendInterface;
use App\Service\DTO\PageContents;
use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;

final readonly class OpenService
{
    public function __construct(
        private BackendInterface $backend,
        private BrowserState $state,
        private PageDisplayService $pageDisplay,
    ) {
    }

    /**
     * @throws BackendError
     * @throws ToolUsageError
     */
    public function __invoke(string $url, int $start_at_line, int $number_of_lines): string
    {
        $trimmedUrl = trim($url);
        $canonicalUrl = Utilities::canonicalizeUrl($trimmedUrl);
        if ('' === $trimmedUrl || '' === $canonicalUrl) {
            throw new ToolUsageError('Invalid URL provided.')->setHint('Provide an absolute URL, e.g. `https://example.com/article`.');
        }

        $startLine = max($start_at_line, 0);
        $numLines = $number_of_lines > 0 ? $number_of_lines : 200;

        $cachedPage = $this->state->getPageByUrl($canonicalUrl);
        $addedNewPage = false;
        if (null === $cachedPage) {
            $fetched = $this->openUrl($canonicalUrl);
            $this->state->addPage($fetched);
            $addedNewPage = true;
        } else {
            $this->state->setCurrentUrl($canonicalUrl);
        }

        try {
            return $this->pageDisplay->showPage($this->state, $startLine, $numLines, $canonicalUrl);
        } catch (ToolUsageError $e) {
            if ($addedNewPage) {
                $this->state->remove($canonicalUrl);
            }
            throw $e;
        }
    }

    /**
     * @throws BackendError
     */
    private function openUrl(string $url): PageContents
    {
        try {
            return $this->backend->fetch($url);
        } catch (\Throwable $e) {
            $msg = Utilities::maybeTruncate($e->getMessage());
            throw new BackendError(\sprintf('Error fetching URL `%s`: %s', Utilities::maybeTruncate($url, 256), $msg), previous: $e)->setHint('This may be a network timeout, server error, or the URL may be inaccessible. Try retrying the request or check if the URL is valid and accessible.');
        }
    }
}
