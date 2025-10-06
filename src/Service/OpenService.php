<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Backend\BackendInterface;
use App\Service\DTO\Extract;
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
    public function __invoke(int|string $linkId = -1, ?string $pageId = null, int $loc = -1, int $numLines = -1): string
    {
        $currPage = null;
        $stayOnCurrentPage = false;
        $directUrlOpen = false;
        $snippet = null;

        if (\is_string($linkId)) {
            $url = $linkId;
            $directUrlOpen = true;
        } else {
            $currPage = $this->state->getPage($pageId);
            if ($linkId >= 0) {
                $url = $currPage->urls[(string) $linkId] ?? '';
                if ('' === $url) {
                    throw new ToolUsageError(\sprintf('Invalid link_id `%s`.', $linkId))
                        ->setHint('Use a `link_id` from the citations in the latest tool response.');
                }
                $snippet = $currPage->snippets[(string) $linkId] ?? null;
            } else {
                $stayOnCurrentPage = true;
                $url = $currPage->url;
            }
        }

        if ($stayOnCurrentPage) {
            $newPage = $currPage;
            \assert($newPage instanceof PageContents);
        } else {
            $newPage = $this->openUrl($url, $directUrlOpen);
            $this->state->addPage($newPage);
        }

        if ($loc < 0) {
            if ($snippet instanceof Extract && null !== $snippet->lineIdx) {
                $loc = max(0, $snippet->lineIdx - 4);
            } else {
                $loc = 0;
            }
        }

        try {
            return $this->pageDisplay->showPage($this->state, $loc, $numLines);
        } catch (ToolUsageError $e) {
            if (!$stayOnCurrentPage) {
                $this->state->popPageStack();
            }
            throw $e;
        }
    }

    private function openUrl(string $url, bool $directUrlOpen): PageContents
    {
        if (!$directUrlOpen) {
            $cached = $this->state->getPageByUrl($url);
            if ($cached) {
                return $cached;
            }
        }
        try {
            return $this->backend->fetch($url);
        } catch (\Throwable $e) {
            $msg = Utilities::maybeTruncate($e->getMessage());
            throw new BackendError(\sprintf('Error fetching URL `%s`: %s', Utilities::maybeTruncate($url, 256), $msg), previous: $e);
        }
    }
}
