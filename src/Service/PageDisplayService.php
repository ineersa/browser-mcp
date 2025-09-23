<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Exception\ToolUsageError;

final class PageDisplayService
{
    public function __construct(
        private int $viewTokens = 1024,
        private string $encodingName = 'o200k_base',
    ) {
    }

    /**
     * Render the current page from the provided BrowserState.
     *
     * @throws ToolUsageError
     */
    public function showPage(BrowserState $state, int $loc = 0, int $numLines = -1): string
    {
        $page = $state->getPage();
        $cursor = $state->getCurrentCursor();
        $lines = Utilities::wrapLines($page->text);
        while (!empty($lines) && '' === $lines[\count($lines) - 1]) {
            array_pop($lines);
        }
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
}
