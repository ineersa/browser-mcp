<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Backend\BackendInterface;
use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;

final class SearchService
{
    public function __construct(
        private readonly BackendInterface $backend,
        private readonly BrowserState     $state,
        private readonly int              $maxSearchResults = 20,
        private readonly int              $viewTokens = 1024,
        private readonly string           $encodingName = 'o200k_base',
    ) {
    }

    public function __invoke(string $query, int $topn = 10, ?string $source = null): string
    {
        try {
            $page = $this->backend->search($query, $this->maxSearchResults);
        } catch (\Throwable $e) {
            $msg = Utilities::maybeTruncate($e->getMessage());
            throw new BackendError(\sprintf('Error during search for `%s`: %s', $query, $msg), previous: $e);
        }
        $this->state->addPage($page);
        try {
            // Compute end location using Utilities::getEndLoc (numLines=-1)
            return $this->showPage(0, -1);
        } catch (ToolUsageError $e) {
            $this->state->popPageStack();
            throw $e;
        }
    }

    /**
     * @throws ToolUsageError
     */
    private function showPage(int $loc = 0, int $numLines = -1): string
    {
        $page = $this->state->getPage();
        $cursor = $this->state->getCurrentCursor();
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
