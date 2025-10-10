<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\DTO\PageContents;
use App\Service\Exception\ToolUsageError;

final class BrowserState
{
    /** @var array<string, PageContents> */
    private array $pages = [];

    /** @var list<string> */
    private array $pageStack = [];

    /** @var array<string,string> */
    private array $pageIdsByUrl = [];

    private int $pageSequence = 0;

    public function reset(): void
    {
        $this->pages = [];
        $this->pageStack = [];
        $this->pageIdsByUrl = [];
        $this->pageSequence = 0;
    }

    public function isEmpty(): bool
    {
        return [] === $this->pageStack;
    }

    public function getCurrentPageId(): string
    {
        if ($this->isEmpty()) {
            throw new ToolUsageError('No pages to access!')->setHint('Run `browser.search` to obtain a `page_id`.');
        }

        $lastIdx = array_key_last($this->pageStack);
        \assert(null !== $lastIdx);

        return $this->pageStack[$lastIdx];
    }

    /**
     * @throws ToolUsageError
     */
    public function addPage(PageContents $page): string
    {
        $pageId = $this->generatePageId();
        $this->pages[$pageId] = $page;
        $this->pageStack[] = $pageId;

        if ('' !== $page->url) {
            $this->pageIdsByUrl[$page->url] = $pageId;
        }

        return $pageId;
    }

    /**
     * @throws ToolUsageError
     */
    public function getPage(?string $pageId = null): PageContents
    {
        if ($this->isEmpty()) {
            throw new ToolUsageError('No pages to access!')->setHint('Run `browser.search` to obtain a `page_id`.');
        }

        $resolvedId = $pageId ?? $this->getCurrentPageId();

        if (!\array_key_exists($resolvedId, $this->pages)) {
            throw new ToolUsageError(\sprintf('Page `%s` is not available in the current browser session.', $resolvedId))->setHint('Use a `page_id` provided in the latest tool response.');
        }

        return $this->pages[$resolvedId];
    }

    public function getPageByUrl(string $url): ?PageContents
    {
        $pageId = $this->pageIdsByUrl[$url] ?? null;

        return null === $pageId ? null : ($this->pages[$pageId] ?? null);
    }

    public function popPageStack(): void
    {
        if ([] === $this->pageStack) {
            return;
        }

        $pageId = array_pop($this->pageStack);

        if (null === $pageId) {
            return;
        }

        // We keep the cached page contents for potential reuse via getPageByUrl().
        // When a page is removed due to an error, also drop its URL mapping.
        foreach ($this->pageIdsByUrl as $url => $id) {
            if ($id === $pageId) {
                unset($this->pageIdsByUrl[$url]);
            }
        }
        unset($this->pages[$pageId]);
    }

    private function generatePageId(): string
    {
        $value = 466560 + $this->pageSequence; // ensures IDs start at `a000`
        ++$this->pageSequence;

        $encoded = strtolower(base_convert((string) $value, 10, 36));
        $encoded = str_pad($encoded, 4, '0', \STR_PAD_LEFT);

        return 'p_'.$encoded;
    }
}
