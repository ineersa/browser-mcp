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
        private readonly BrowserState $state,
        private readonly PageDisplayService $pageDisplay,
    ) {
    }

    /**
     * @throws BackendError
     * @throws ToolUsageError
     */
    public function __invoke(string $query, int $topn = 10): string
    {
        try {
            $page = $this->backend->search($query, $topn);
        } catch (\Throwable $e) {
            $msg = Utilities::maybeTruncate($e->getMessage());
            throw new BackendError(\sprintf('Error during search for `%s`: %s', $query, $msg), previous: $e);
        }
        $this->state->reset();
        $this->state->addPage($page);
        try {
            // Compute end location using Utilities::getEndLoc (numLines=-1)
            return $this->pageDisplay->showPage($this->state, 0, -1);
        } catch (ToolUsageError $e) {
            // Can't show but page already in state, so we have to pop it
            $this->state->popPageStack();
            throw $e;
        }
    }
}
