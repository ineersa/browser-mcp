<?php

declare(strict_types=1);

namespace App\Tools;

use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\SearchService;
use Mcp\Schema\Content\StructuredContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

final class SearchTool
{
    public const string NAME = 'search';
    public const string TITLE = 'Search for information';
    public const string DESCRIPTION = 'Searches for information related to `query` and displays `topn` results.';

    public function __construct(
        private readonly SearchService $searchService,
    ) {
    }

    /**
     * @return array
     */
    public function __invoke(
        string $query,
        int $topn = 10,
    ): CallToolResult {
        try {
            $result = $this->searchService->__invoke($query, $topn);
            $content = new TextContent($result);
            $structured = new StructuredContent(
                [
                    'result' => $result,
                ]
            );

            return new CallToolResult([$content], $structured, false);
        } catch (ToolUsageError|BackendError $exception) {
            $result = "Result: error\n Error Message: ".$exception->getMessage()."\n Hint: ".$exception->getHint();
            $content = new TextContent(text: $result, isError: true);
            $structured = new StructuredContent(
                [
                    'result' => $result,
                ]
            );

            return new CallToolResult([$content], $structured, true);
        }
    }
}
