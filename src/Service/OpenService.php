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
        private int                       $viewTokens = 1024,
        private string                    $encodingName = 'o200k_base',
    ) {
    }

    public function __invoke(int|string $id = -1, int $cursor = -1, int $loc = -1, int $numLines = -1, bool $viewSource = false, ?string $source = null): string
    {
        $currPage = null;
        $stayOnCurrentPage = false;
        $directUrlOpen = false;
        $url = '';
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

        return $this->showPageSafely($loc, $numLines);
    }

    public function open(int|string $id = -1, int $cursor = -1, int $loc = -1, int $numLines = -1, bool $viewSource = false, ?string $source = null): string
    {
        return $this($id, $cursor, $loc, $numLines, $viewSource, $source);
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
            $msg = Utilities::maybeTruncate($e->getMessage() ?? '', 1024);
            throw new BackendError(\sprintf('Error fetching URL `%s`: %s', Utilities::maybeTruncate($url, 256), $msg), previous: $e);
        }
    }

    private function showPage(int $loc = 0, int $numLines = -1): string
    {
        $page = $this->state->getPage();
        $cursor = $this->state->getCurrentCursor();
        $lines = Utilities::wrapLines($page->text, 80);
        $totalLines = \count($lines);
        if ($loc >= $totalLines) {
            throw new ToolUsageError(\sprintf('Invalid location parameter: `%d`. Cannot exceed page maximum of %d.', $loc, $totalLines - 1));
        }
        $endLoc = Utilities::getEndLoc($loc, $numLines, $totalLines, $lines, $this->viewTokens, $this->encodingName);
        $linesToShow = \array_slice($lines, $loc, $endLoc - $loc);
        $body = Utilities::joinLines($linesToShow, true, $loc);
        $scrollbar = \sprintf('viewing lines [%d - %d] of %d', $loc, $endLoc - 1, $totalLines - 1);

        return Utilities::makeDisplay($page, $cursor, $body, $scrollbar);
    }

    private function showPageSafely(int $loc = 0, int $numLines = -1): string
    {
        try {
            return $this->showPage($loc, $numLines);
        } catch (ToolUsageError $e) {
            $this->state->popPageStack();
            throw $e;
        }
    }
}
