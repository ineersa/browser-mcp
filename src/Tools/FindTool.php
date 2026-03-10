<?php

declare(strict_types=1);

namespace App\Tools;

use App\Domain\Find\FindMatchMode;
use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\FindService;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

final class FindTool
{
    public const string NAME = 'find';
    public const string TITLE = 'Find text in page';
    public const string DESCRIPTION = 'Finds text matches within the page at `url`. Provide `query` as plain text and optional `match` mode (`contains` or `exact`). `contains` is case-insensitive and behaves like grep/ctrl+f. `exact` is case-sensitive and punctuation-sensitive. Optional `context_lines` controls how many lines are shown per match (default 5). Both `url` and `query` are required; the page is fetched (and cached) by URL before searching, so call this directly whenever you already know the destination and phrase—no prior `search` or `open` call is needed. Results include a References section for inline markers, and when no matches are found the response explains that no visible matches exist (useful for JSON or structured data pages).';

    public function __construct(
        private readonly FindService $findService,
    ) {
    }

    public function __invoke(
        string $url,
        string $query,
        string $match = 'contains',
        int $context_lines = 5,
    ): CallToolResult {
        try {
            if ('' === trim($url)) {
                throw new ToolUsageError('Invalid URL provided. The FindTool requires a non-empty URL.')->setHint('Use an absolute URL from a previous search result or open call.');
            }

            if ('' === trim($query)) {
                throw new ToolUsageError('Invalid query provided. The FindTool requires a non-empty query string.')->setHint('Provide plain text to search for within the page.');
            }

            $matchMode = FindMatchMode::tryFrom(trim($match));
            if (null === $matchMode) {
                throw new ToolUsageError('Invalid match mode provided.')->setHint('Use `contains` (default) or `exact`.');
            }
            if ($context_lines < 1) {
                throw new ToolUsageError('Invalid context_lines provided.')->setHint('Use an integer greater than or equal to 1.');
            }

            $result = $this->findService->__invoke(url: trim($url), query: trim($query), match: $matchMode, contextLines: $context_lines);

            $content = new TextContent($result);

            return new CallToolResult([$content], false, ['result' => $result]);
        } catch (ToolUsageError|BackendError $exception) {
            $result = "Result: error\n Error Message: ".$exception->getMessage()."\n Hint: ".$exception->getHint();
            $content = new TextContent(text: $result);

            return new CallToolResult([$content], true, ['result' => $result]);
        }
    }
}
