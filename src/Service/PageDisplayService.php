<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\AppConfig;
use App\Service\DTO\PageContents;
use App\Service\Exception\ToolUsageError;

readonly class PageDisplayService
{
    public function __construct(
        private AppConfig $config,
    ) {
    }

    /**
     * Render the current page from the provided BrowserState.
     *
     * @throws ToolUsageError
     */
    public function showPage(BrowserState $state, int $loc = 0, int $numLines = -1, ?string $url = null): string
    {
        $page = $state->getPage($url);

        return $this->render($page, $loc, $numLines);
    }

    /**
     * Render a standalone page without storing it in BrowserState.
     *
     * @throws ToolUsageError
     */
    public function renderStandalone(PageContents $page, int $loc = 0, int $numLines = -1): string
    {
        return $this->render($page, $loc, $numLines);
    }

    /**
     * @throws ToolUsageError
     */
    private function render(PageContents $page, int $loc, int $numLines): string
    {
        $lines = Utilities::wrapLines($page->text);
        while (!empty($lines) && '' === $lines[\count($lines) - 1]) {
            array_pop($lines);
        }
        $totalLines = \count($lines);
        if ($loc >= $totalLines) {
            throw new ToolUsageError(\sprintf('Invalid start_at_line parameter: `%d`. Cannot exceed page maximum of %d.', $loc, max(0, $totalLines - 1)))->setHint('Choose a smaller `start_at_line` within the page line count.');
        }
        $endLoc = Utilities::getEndLoc(
            $loc,
            $numLines,
            $totalLines,
            $lines,
            $this->config->getSearchViewTokens(),
            $this->config->getSearchEncodingName(),
        );
        $linesToShow = \array_slice($lines, $loc, $endLoc - $loc);
        $body = Utilities::joinLines($linesToShow, true, $loc);
        $scrollbar = \sprintf('viewing lines [%d - %d] of %d', $loc, $endLoc - 1, $totalLines - 1);

        return Utilities::makeDisplay($page, $body, $scrollbar);
    }
}
