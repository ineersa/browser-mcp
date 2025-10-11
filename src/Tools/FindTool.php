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
    public const string DESCRIPTION = 'Finds regex matches within the page identified by `page_id`. Both `regex` and `page_id` are required parameters. The response is a new virtual page prefixed with its own `[PAGE_ID:{page_id}]`.';

    public function __construct(
        private readonly FindService $findService,
    ) {
    }

    public function __invoke(
        string $regex,
        string $pageId,
    ): CallToolResult {
        // Validate required parameters
        if ('' === trim($regex)) {
            throw new ToolUsageError('Invalid regex provided. The FindTool requires a non-empty regex pattern.')->setHint('Provide a valid regex pattern to search for within the page.');
        }

        if ('' === trim($pageId)) {
            throw new ToolUsageError('Invalid page ID provided. The FindTool requires a non-empty page ID.')->setHint('Use the page ID from the latest tool response, typically shown as [PAGE_ID:{page_id}] in the tool output.');
        }

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
