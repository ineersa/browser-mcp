<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\DTO\PageContents;
use App\Service\Exception\ToolUsageError;
use JsonSerializable;

final class BrowserState
{
    /** @var array<string, PageContents> */
    private array $pages = [];
    /** @var list<string> */
    private array $pageStack = [];

    public function getCurrentCursor(): int
    {
        return \count($this->pageStack) - 1;
    }

    public function addPage(PageContents $page): void
    {
        $this->pages[$page->url] = $page;
        $this->pageStack[] = $page->url;
    }

    /**
     * @throws ToolUsageError
     */
    public function getPage(int $cursor = -1): PageContents
    {
        if ($this->getCurrentCursor() < 0) {
            throw new ToolUsageError('No pages to access!');
        }
        if (-1 === $cursor || $cursor === $this->getCurrentCursor()) {
            return $this->pages[$this->pageStack[$this->getCurrentCursor()]];
        }
        if (!\array_key_exists($cursor, $this->pageStack)) {
            throw new ToolUsageError(\sprintf('Cursor `%d` is out of range. Available cursor indices: [0 - %d].', $cursor, $this->getCurrentCursor()));
        }
        $pageUrl = $this->pageStack[$cursor];

        return $this->pages[$pageUrl];
    }

    public function getPageByUrl(string $url): ?PageContents
    {
        return $this->pages[$url] ?? null;
    }

    public function popPageStack(): void
    {
        if (!empty($this->pageStack)) {
            array_pop($this->pageStack);
        }
    }
}
