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
    public const string DESCRIPTION = 'Runs a web search for `query` and lists up to `topn` results. Each entry is numbered and includes a canonical `URL:` plus a short summary—use that URL with `browser.open` or `browser.find`. A References section maps each result number to its canonical URL for citations. Avoid quoting more than 10 words from any single result.';

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
