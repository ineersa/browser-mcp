<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Exception\ToolUsageError;

final class FindService
{
    public function __construct(
        private readonly BrowserState $state,
        private int                   $viewTokens = 1024,
        private string                $encodingName = 'o200k_base',
    ) {
    }

    public function __invoke(string $pattern, int $cursor = -1): string
    {
        $page = $this->state->getPage($cursor);
        if (null !== $page->snippets) {
            throw new ToolUsageError('Cannot run `find` on search results page or find results page');
        }
        $pc = Utilities::runFindInPage(mb_strtolower((string) $pattern), $page);
        $this->state->addPage($pc);

        return $this->showPageSafely(0, -1);
    }

    public function find(string $pattern, int $cursor = -1): string
    {
        return $this($pattern, $cursor);
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
