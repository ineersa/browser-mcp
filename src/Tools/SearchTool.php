<?php

declare(strict_types=1);

namespace App\Tools;

use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\SearchService;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

final class SearchTool
{
    public const string NAME = 'search';
    public const string TITLE = 'Search for information';
    public const string DESCRIPTION = 'Runs a web search for `query` and lists up to `topn` results. The response begins with `[PAGE_ID:{page_id}]`; use that `page_id` when calling other tools. Each search hit exposes a `link_id` inside its citation marker `【{link_id}†…】`. Cite passages with `【{link_id}†L{line_start}(-L{line_end})?】` and avoid quoting more than 10 words.';

    public function __construct(
        private readonly SearchService $searchService,
    ) {
    }

    public function __invoke(
        string $query,
        int $topn = 5,
    ): CallToolResult {
        try {
            $result = $this->searchService->__invoke($query, $topn);
            $content = new TextContent($result);

            return new CallToolResult([$content], null, false);
        } catch (ToolUsageError|BackendError $exception) {
            $result = "Result: error\n Error Message: ".$exception->getMessage()."\n Hint: ".$exception->getHint();
            $content = new TextContent(text: $result);

            return new CallToolResult([$content], null, true);
        }
    }
}
