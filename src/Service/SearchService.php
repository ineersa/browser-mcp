<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Backend\BackendInterface;
use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;

final readonly class SearchService
{
    public function __construct(
        private BackendInterface $backend,
        private BrowserState $state,
        private PageDisplayService $pageDisplay,
    ) {
    }

    /**
     * @throws BackendError
     * @throws ToolUsageError
     */
    public function __invoke(string $query, int $topn = 5): string
    {
        if (empty($query)) {
            throw new ToolUsageError('query cannot be empty')->setHint('Provide query to search');
        }

        if ($topn < 1 || $topn > 10) {
            throw new ToolUsageError("topn can't be less than 1 and more than 10")->setHint('Provide topn in range 1-10');
        }

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
