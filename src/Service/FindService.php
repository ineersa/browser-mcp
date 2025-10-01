<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Exception\ToolUsageError;

final readonly class FindService
{
    public function __construct(
        private BrowserState $state,
        private PageDisplayService $pageDisplay,
        private int $findContextLines = 4,
    ) {
    }

    /**
     * @throws ToolUsageError
     */
    public function __invoke(?string $pattern = null, ?string $regex = null, int $cursor = -1): string
    {
        if (null !== $pattern && null !== $regex) {
            throw new ToolUsageError('Provide either `pattern` or `regex`, not both.');
        }

        if (null === $pattern || '' === $pattern) {
            $pattern = null;
        }

        if (null === $regex || '' === $regex) {
            $regex = null;
        }

        if (null === $pattern && null === $regex) {
            throw new ToolUsageError('Provide a non-empty `pattern` or `regex` to search for.');
        }

        $page = $this->state->getPage($cursor);
        if (null !== $page->snippets) {
            throw new ToolUsageError('Cannot run `find` on search results page or find results page');
        }
        $pageContent = Utilities::runFindInPage(
            page: $page,
            pattern: $pattern,
            regex: $regex,
            numShowLines: $this->findContextLines,
        );
        $this->state->addPage($pageContent);

        try {
            return $this->pageDisplay->showPage($this->state, 0, -1);
        } catch (ToolUsageError $e) {
            $this->state->popPageStack();
            throw $e;
        }
    }
}
