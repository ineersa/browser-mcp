<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Exception\ToolUsageError;

final class FindService
{
    public function __construct(
        private readonly BrowserState $state,
        private readonly PageDisplayService $pageDisplay,
    ) {
    }

    public function __invoke(string $pattern, int $cursor = -1): string
    {
        $page = $this->state->getPage($cursor);
        if (null !== $page->snippets) {
            throw new ToolUsageError('Cannot run `find` on search results page or find results page');
        }
        $pageContent = Utilities::runFindInPage(mb_strtolower($pattern), $page);
        $this->state->addPage($pageContent);

        try {
            return $this->pageDisplay->showPage($this->state, 0, -1);
        } catch (ToolUsageError $e) {
            $this->state->popPageStack();
            throw $e;
        }
    }
}
