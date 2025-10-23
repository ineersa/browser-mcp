<?php

declare(strict_types=1);

namespace App\Tools;

use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\FindService;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\CallToolResultInterface;
use Mcp\Schema\Result\CallToolStructuredContentResult;

final class FindTool
{
    public const string NAME = 'find';
    public const string TITLE = 'Find pattern in page';
    public const string DESCRIPTION = 'Finds regex matches within the page at `url`. The `regex` parameter must be a valid PCRE regular expression (include delimiters like `/pattern/`, e.g. `/some text/iu`). Both `url` and `regex` are required; the page is fetched (and cached) by URL before searching. Results include a References section for inline markers, and if no matches are found the tool suggests next steps (broader regex or opening more context).';

    public function __construct(
        private readonly FindService $findService,
    ) {
    }

    public function __invoke(
        string $url,
        string $regex,
    ): CallToolResultInterface {
        try {
            if ('' === trim($url)) {
                throw new ToolUsageError('Invalid URL provided. The FindTool requires a non-empty URL.')->setHint('Use an absolute URL from a previous search result or open call.');
            }

            if ('' === trim($regex)) {
                throw new ToolUsageError('Invalid regex provided. The FindTool requires a non-empty regex pattern.')->setHint('Provide a valid regular expression to search for within the page.');
            }

            $result = $this->findService->__invoke(url: trim($url), regex: trim($regex));

            $content = new TextContent($result);

            $callToolResult = new CallToolResult([$content], false);

            return new CallToolStructuredContentResult(['result' => $result], $callToolResult);
        } catch (ToolUsageError|BackendError $exception) {
            $result = "Result: error\n Error Message: ".$exception->getMessage()."\n Hint: ".$exception->getHint();
            $content = new TextContent(text: $result);

            return new CallToolStructuredContentResult(['result' => $result], new CallToolResult([$content], true));
        }
    }
}
