<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Backend\BackendInterface;
use App\Service\DTO\PageContents;
use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;

final readonly class FindService
{
    public function __construct(
        private BackendInterface $backend,
        private BrowserState $state,
        private PageDisplayService $pageDisplay,
        private int $findContextLines = 4,
    ) {
    }

    /**
     * @throws ToolUsageError
     * @throws BackendError
     */
    public function __invoke(string $url, string $regex): string
    {
        $trimmedRegex = trim($regex);
        if ('' === $trimmedRegex) {
            throw new ToolUsageError('Provide a non-empty `regex` to search for.');
        }

        $canonicalUrl = Utilities::canonicalizeUrl($url);
        if ('' === trim($url) || '' === $canonicalUrl) {
            throw new ToolUsageError('Invalid URL provided.')->setHint('Provide an absolute URL, e.g. `https://example.com/article`.');
        }

        $page = $this->state->getPageByUrl($canonicalUrl);
        if (null === $page) {
            $page = $this->fetchPage($canonicalUrl);
            $this->state->addPage($page);
        } else {
            $this->state->setCurrentUrl($canonicalUrl);
        }

        if (null !== $page->snippets) {
            throw new ToolUsageError('Cannot run `find` on search results page or find results page');
        }
        $pageContent = Utilities::runFindInPage(
            page: $page,
            regex: $trimmedRegex,
            numShowLines: $this->findContextLines,
        );
        $resultUrl = $this->state->addPage($pageContent);

        try {
            return $this->pageDisplay->showPage($this->state, 0, -1, $resultUrl);
        } catch (ToolUsageError $e) {
            $this->state->remove($resultUrl);
            throw $e;
        }
    }

    /**
     * @throws BackendError
     */
    private function fetchPage(string $url): PageContents
    {
        try {
            return $this->backend->fetch($url);
        } catch (\Throwable $e) {
            $msg = Utilities::maybeTruncate($e->getMessage());
            throw new BackendError(\sprintf('Error fetching URL `%s`: %s', Utilities::maybeTruncate($url, 256), $msg), previous: $e);
        }
    }
}
