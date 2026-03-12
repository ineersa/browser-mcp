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
    public const string DESCRIPTION = 'Find text in a single page at `url` using `query` and `match` mode. Choose mode carefully: `contains` (default) is case-insensitive and whitespace-flexible (spaces/newlines can match each other), good for broad discovery and paraphrased casing, e.g. query `symfony messenger` can match `Symfony` on one line and `Messenger` on the next. `exact` is strict: case-sensitive and whitespace/punctuation-sensitive, good when you must verify literal text, identifiers, flags, headings, or code snippets; it only matches the exact characters (including newlines if present). `context_lines` controls chunk size around each hit (default 5). Both `url` and `query` are required. Returns TOON output with `url`, `query`, `match`, and `matches` (`id`, `line`, `chunk`).';

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

            return new CallToolResult([$content], false);
        } catch (ToolUsageError|BackendError $exception) {
            $result = "Result: error\n Error Message: ".$exception->getMessage()."\n Hint: ".$exception->getHint();
            $content = new TextContent(text: $result);

            return new CallToolResult([$content], true);
        }
    }
}
