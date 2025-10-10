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
    public function __invoke(?string $regex = null, ?string $pageId = null): string
    {
        if (null === $regex || '' === $regex) {
            throw new ToolUsageError('Provide a non-empty `regex` to search for.');
        }

        $page = $this->state->getPage($pageId);
        if (null !== $page->snippets) {
            throw new ToolUsageError('Cannot run `find` on search results page or find results page');
        }
        $pageContent = Utilities::runFindInPage(
            page: $page,
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
