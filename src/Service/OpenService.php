<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Backend\BackendInterface;
use App\Service\DTO\Extract;
use App\Service\DTO\PageContents;
use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;

final class OpenService
{
    public function __construct(
        private readonly BackendInterface $backend,
        private readonly BrowserState     $state,
        private readonly PageDisplayService $pageDisplay,
    ) {
    }

    public function __invoke(int|string $id = -1, int $cursor = -1, int $loc = -1, int $numLines = -1, bool $viewSource = false): string
    {
        $currPage = null;
        $stayOnCurrentPage = false;
        $directUrlOpen = false;
        $snippet = null;

        if (\is_string($id)) {
            $url = $id;
            $directUrlOpen = true;
        } else {
            $currPage = $this->state->getPage($cursor);
            if ($id >= 0) {
                $url = $currPage->urls[(string) $id] ?? '';
                if ('' === $url) {
                    throw new ToolUsageError(\sprintf('Invalid link id `%s`.', (string) $id));
                }
                $snippet = $currPage->snippets[(string) $id] ?? null;
            } else {
                if (!$viewSource) {
                    $stayOnCurrentPage = true;
                }
                $url = $currPage->url;
            }
        }

        if ($viewSource) {
            $url = BackendInterface::VIEW_SOURCE_PREFIX.$url;
            $snippet = null;
        }

        if ($stayOnCurrentPage) {
            $newPage = $currPage;
            \assert($newPage instanceof PageContents);
        } else {
            $newPage = $this->openUrl($url, $directUrlOpen);
        }

        $this->state->addPage($newPage);

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
            $this->state->popPageStack();
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
            $msg = Utilities::maybeTruncate($e->getMessage() ?? '');
            throw new BackendError(\sprintf('Error fetching URL `%s`: %s', Utilities::maybeTruncate($url, 256), $msg), previous: $e);
        }
    }
}
