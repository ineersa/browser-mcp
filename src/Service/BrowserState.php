<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\DTO\PageContents;
use App\Service\Exception\ToolUsageError;
use App\Service\Utilities;

final class BrowserState
{
    /** @var array<string, PageContents> */
    private array $pagesByUrl = [];

    /** @var list<string> */
    private array $history = [];

    private ?string $currentUrl = null;

    public function reset(): void
    {
        $this->pagesByUrl = [];
        $this->history = [];
        $this->currentUrl = null;
    }

    public function isEmpty(): bool
    {
        return null === $this->currentUrl;
    }

    /**
     * @throws ToolUsageError
     */
    public function getCurrentUrl(): string
    {
        if ($this->isEmpty()) {
            throw new ToolUsageError('No pages to access!')->setHint('Run `browser.open` with a URL to load a page first.');
        }

        return (string) $this->currentUrl;
    }

    /**
     * @throws ToolUsageError
     */
    public function addPage(PageContents $page): string
    {
        $canonicalUrl = Utilities::canonicalizeUrl($page->url);
        if ('' === $canonicalUrl) {
            throw new ToolUsageError('Cannot cache a page without a URL.')->setHint('Only pages with a valid URL can be cached.');
        }

        $this->pagesByUrl[$canonicalUrl] = $page;
        $this->touchHistory($canonicalUrl);

        return $canonicalUrl;
    }

    /**
     * @throws ToolUsageError
     */
    public function getPage(?string $url = null): PageContents
    {
        if ($this->isEmpty()) {
            throw new ToolUsageError('No pages to access!')->setHint('Run `browser.open` with a URL to load a page first.');
        }

        $resolvedUrl = $url ?? $this->getCurrentUrl();
        $canonical = Utilities::canonicalizeUrl($resolvedUrl);

        if (!\array_key_exists($canonical, $this->pagesByUrl)) {
            throw new ToolUsageError(\sprintf('Page `%s` is not available in the current browser session.', $resolvedUrl))->setHint('Open the page with `browser.open` first.');
        }

        $this->currentUrl = $canonical;

        return $this->pagesByUrl[$canonical];
    }

    public function getPageByUrl(string $url): ?PageContents
    {
        $canonical = Utilities::canonicalizeUrl($url);

        return $this->pagesByUrl[$canonical] ?? null;
    }

    public function remove(string $url): void
    {
        $canonical = Utilities::canonicalizeUrl($url);
        unset($this->pagesByUrl[$canonical]);
        $this->history = array_values(array_filter(
            $this->history,
            static fn (string $entry): bool => $entry !== $canonical,
        ));

        if ($this->currentUrl === $canonical) {
            $this->currentUrl = empty($this->history) ? null : $this->history[\count($this->history) - 1];
        }
    }

    /**
     * @throws ToolUsageError
     */
    public function setCurrentUrl(string $url): void
    {
        $canonical = Utilities::canonicalizeUrl($url);

        if (!\array_key_exists($canonical, $this->pagesByUrl)) {
            throw new ToolUsageError(\sprintf('Page `%s` is not available in the current browser session.', $url))->setHint('Open the page with `browser.open` first.');
        }

        $this->touchHistory($canonical);
    }

    private function touchHistory(string $canonicalUrl): void
    {
        $idx = array_search($canonicalUrl, $this->history, true);
        if (false !== $idx) {
            unset($this->history[$idx]);
            $this->history = array_values($this->history);
        }
        $this->history[] = $canonicalUrl;
        $this->currentUrl = $canonicalUrl;
    }
}
