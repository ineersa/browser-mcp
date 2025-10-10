<?php

declare(strict_types=1);

namespace App\Tools;

use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\OpenService;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

final class OpenTool
{
    public const string NAME = 'open_result';
    public const string TITLE = 'Open a search result page';
    public const string DESCRIPTION = 'Opens the search result with the specified integer `link_id` from the page indicated by `page_id`, starting at line number `loc` and showing `num_lines` lines. Valid `link_id` values are integer IDs from search results displayed inside references such as `【{link_id}†…】`. If `page_id` is omitted, the most recently viewed page is used. Use link_id = -1 to scroll the current page. The tool response is prefixed with `[PAGE_ID:{page_id}]` and includes the viewport range. Cite with `【{link_id}†L{line_start}(-L{line_end})?】`.';

    public function __construct(
        private readonly OpenService $openService,
    ) {
    }

    public function __invoke(
        int $linkId = -1,
        ?string $pageId = null,
        int $loc = -1,
        int $numLines = -1,
    ): CallToolResult {
        try {
            $result = $this->openService->__invoke($linkId, $pageId, $loc, $numLines);

            $content = new TextContent($result);

            return new CallToolResult([$content], null, false);
        } catch (ToolUsageError|BackendError $exception) {
            $result = "Result: error\n Error Message: ".$exception->getMessage()."\n Hint: ".$exception->getHint();
            $content = new TextContent(text: $result);

            return new CallToolResult([$content], null, true);
        }
    }
}
