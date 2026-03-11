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
    public const string TITLE = 'Open a page';
    public const string DESCRIPTION = <<<'DESC'
Loads `url` and returns a window of page text. The response starts with metadata: title (with domain), optional `URL: <canonical URL>`, and a bold `viewing lines [start - end] of total` progress line. The body contains numbered `L{n}:` lines only. `startAtLine` is optional; when omitted, the tool auto-selects a useful window from recent search snippets for this URL. If no snippet can be located, it falls back to top-of-page output. `numberOfLines` controls window size (default `50`) unless `fetchAll: true`, which returns the entire page body in one call. References are metadata and never count toward line totals.
DESC;

    public function __construct(
        private readonly OpenService $openService,
    ) {
    }

    public function __invoke(
        string $url,
        ?int $startAtLine = null,
        int $numberOfLines = 50,
        bool $fetchAll = false,
    ): CallToolResult {
        try {
            if ('' === trim($url)) {
                throw new ToolUsageError('Invalid URL provided.')->setHint('Use an absolute URL such as `https://example.com/article`.');
            }

            if (null !== $startAtLine && $startAtLine < 0) {
                throw new ToolUsageError('`startAtLine` must be zero or greater.')->setHint('Use 0 for the top of the page.');
            }

            if (!$fetchAll && $numberOfLines <= 0) {
                throw new ToolUsageError('`numberOfLines` must be greater than zero when `fetchAll` is false.')->setHint('Set `numberOfLines` to a positive integer (defaults to 50) or pass `fetchAll: true` to retrieve the entire page in one call.');
            }

            $result = $this->openService->__invoke(
                $url,
                $startAtLine,
                $fetchAll ? -1 : $numberOfLines,
                $fetchAll,
            );

            $content = new TextContent($result);

            return new CallToolResult([$content], false);
        } catch (ToolUsageError|BackendError $exception) {
            $result = "Result: error\n Error Message: ".$exception->getMessage()."\n Hint: ".$exception->getHint();
            $content = new TextContent(text: $result);

            return new CallToolResult([$content], true);
        }
    }
}
