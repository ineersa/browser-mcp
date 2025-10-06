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
    public const string NAME = 'open';
    public const string TITLE = 'Open a link or page';
    public const string DESCRIPTION = 'Opens the link `id` from the page indicated by `cursor` starting at line number `loc`, showing `num_lines` lines. Valid link `id` displayed with the formatting: `【{id}†.*】`. The `cursor` appears in brackets before each browsing display: `[CURSOR:#{cursor}]`. If `cursor` is not provided, the most recent page is implied. If `id` is a string, it is treated as a fully qualified URL. If `loc` is not provided, the viewport will be positioned at the beginning of the document or centered on the most relevant passage, if available. Use this function without `id` to scroll to a new location of an opened page.';

    public function __construct(
        private readonly OpenService $openService,
    ) {
    }

    public function __invoke(
        int|string $id = -1,
        int $cursor = -1,
        int $loc = -1,
        int $numLines = -1,
    ): CallToolResult {
        try {
            $result = $this->openService->__invoke($id, $cursor, $loc, $numLines);

            $content = new TextContent($result);

            return new CallToolResult([$content], null, false);
        } catch (ToolUsageError|BackendError $exception) {
            $result = "Result: error\n Error Message: ".$exception->getMessage()."\n Hint: ".$exception->getHint();
            $content = new TextContent(text: $result);

            return new CallToolResult([$content], null, true);
        }
    }
}
