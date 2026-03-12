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
Loads `url` and returns a window of page text. Output begins with metadata (title + domain, optional canonical `URL:` line), then a bold progress header `viewing lines [start - end] of total`, then numbered body lines (`L{n}: ...`). Line numbers are zero-based.

Window selection:
- If `startAtLine` is provided, windowing is deterministic: start there and show `numberOfLines` (default `50`).
- If `startAtLine` is omitted, the tool first tries to anchor near recent `search` snippets for the same URL; otherwise it falls back to top-of-page.
- Auto-selected windows are larger (about 100 lines) to provide scanning context.

`fetchAll: true` ignores window size and returns the full page body in one call. Use this sparingly on very long pages. References are metadata and do not affect line counts.
DESC;

    // @phpstan-ignore shipmonk.deadMethod
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
