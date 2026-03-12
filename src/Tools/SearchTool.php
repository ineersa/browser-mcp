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
    public const string DESCRIPTION = <<<'DESC'
Runs web search for `query` and returns ranked results with canonical URLs and short summaries. Use this tool for discovery (finding candidate sources) before deep reading. Query tips: include specific nouns, version numbers, product/library names, and error strings for higher precision; avoid very long natural-language prompts. `topn` controls recall (default 5): use 3-5 for focused lookup, 8-10 when coverage matters. After choosing a result URL, use `open` to inspect page text and `find` to verify exact phrases/snippets. Response includes numbered results and a References map (`[id] -> URL`) for citation-friendly follow-up.
DESC;

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

            return new CallToolResult([$content], false);
        } catch (ToolUsageError|BackendError $exception) {
            $result = "Result: error\n Error Message: ".$exception->getMessage()."\n Hint: ".$exception->getHint();
            $content = new TextContent(text: $result);

            return new CallToolResult([$content], true);
        }
    }
}
