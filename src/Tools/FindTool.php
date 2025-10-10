<?php

declare(strict_types=1);

namespace App\Tools;

use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\FindService;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

final class FindTool
{
    public const string NAME = 'find';
    public const string TITLE = 'Find pattern in page';
    public const string DESCRIPTION = 'Finds regex matches within the page identified by `page_id`. If `page_id` is omitted, the most recently viewed page is used. The response is a new virtual page prefixed with its own `[PAGE_ID:{page_id}]`. Cite results with the `link_id` shown in each snippet reference.';

    public function __construct(
        private readonly FindService $findService,
    ) {
    }

    public function __invoke(?string $regex = null, ?string $pageId = null): CallToolResult
    {
        try {
            $result = $this->findService->__invoke(regex: $regex, pageId: $pageId);

            $content = new TextContent($result);

            return new CallToolResult([$content], null, false);
        } catch (ToolUsageError|BackendError $exception) {
            $result = "Result: error\n Error Message: ".$exception->getMessage()."\n Hint: ".$exception->getHint();
            $content = new TextContent(text: $result);

            return new CallToolResult([$content], null, true);
        }
    }
}
