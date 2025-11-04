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
    public const string DESCRIPTION = 'Finds regex matches within the page at `url`. The `regex` parameter must be a valid PCRE regular expression (include delimiters like `/pattern/`, e.g. `/some text/iu`). Both `url` and `regex` are required; the page is fetched (and cached) by URL before searching, so call this directly whenever you already know the destination and pattern—no prior `search` or `open` call is needed. Results include a References section for inline markers, and when no matches are found the response explains that no visible matches exist (useful for JSON or structured data pages).';

    public function __construct(
        private readonly FindService $findService,
    ) {
    }

    public function __invoke(
        string $url,
        string $regex,
    ): CallToolResult {
        try {
            if ('' === trim($url)) {
                throw new ToolUsageError('Invalid URL provided. The FindTool requires a non-empty URL.')->setHint('Use an absolute URL from a previous search result or open call.');
            }

            if ('' === trim($regex)) {
                throw new ToolUsageError('Invalid regex provided. The FindTool requires a non-empty regex pattern.')->setHint('Provide a valid regular expression to search for within the page.');
            }

            $result = $this->findService->__invoke(url: trim($url), regex: trim($regex));

            $content = new TextContent($result);

            return new CallToolResult([$content], false, ['result' => $result]);
        } catch (ToolUsageError|BackendError $exception) {
            $result = "Result: error\n Error Message: ".$exception->getMessage()."\n Hint: ".$exception->getHint();
            $content = new TextContent(text: $result);

            return new CallToolResult([$content], true, ['result' => $result]);
        }
    }
}
