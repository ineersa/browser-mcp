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
Loads `url` and returns a window of the page text. The response always begins with a header: first the page title (with domain), then `URL: <canonical URL>` when available, followed by a bold `viewing lines [start - end] of total` progress line. That header (everything before the first `L{n}:` body line) is authoritative metadata: read it, rely on it to understand progress, and do not treat it as part of the line window. Provide `start_at_line` (0-based) and `number_of_lines` (how many lines to show, typically 50). The body that follows consists of numbered `L{n}:` lines matching those parameters exactly. Pages are cached by URL so you can scroll or run `find` without re-fetching. The response ends with a References section that lists canonical URLs for any inline citations; references are metadata only and never count toward the window of lines or the progress totals. Call this directly when you already have the exact URL; no search step is required. Before leaving a page, either keep opening the next window until the header confirms you have seen the final line, or deliberately switch to `browser.find` on THIS page. Treat pages over 400 lines as "large": consider using `browser.find` to jump to the right area first or use smaller windows; for shorter pages, open the whole body in a single call. IF you had part of the page opened never duplicate it and open next part from `start_at_line`. If the user asks for "all", "complete", "thorough", "precise", "grounded", or "verified" information, treat that as a hard requirement to continue sequential opens until the scrollbar shows the final line before responding. When you truly need the entire page in one shot, set `fetch_all: true`—that overrides the line limit and retrieves every visible line in a single response (header and references still remain metadata and do not count toward the body lines).
DESC;

    public function __construct(
        private readonly OpenService $openService,
    ) {
    }

    public function __invoke(
        string $url,
        int $start_at_line,
        int $number_of_lines = 50,
        bool $fetch_all = false,
    ): CallToolResult {
        try {
            if ('' === trim($url)) {
                throw new ToolUsageError('Invalid URL provided.')->setHint('Use an absolute URL such as `https://example.com/article`.');
            }

            if ($start_at_line < 0) {
                throw new ToolUsageError('`start_at_line` must be zero or greater.')->setHint('Use 0 for the top of the page.');
            }

            if (!$fetch_all && $number_of_lines <= 0) {
                throw new ToolUsageError('`number_of_lines` must be greater than zero when `fetch_all` is false.')->setHint('Set `number_of_lines` to a positive integer (defaults to 50) or pass `fetch_all: true` to retrieve the entire page in one call.');
            }

            $result = $this->openService->__invoke(
                $url,
                $start_at_line,
                $fetch_all ? -1 : $number_of_lines,
                $fetch_all,
            );

            $content = new TextContent($result);

            return new CallToolResult([$content], false, ['result' => $result]);
        } catch (ToolUsageError|BackendError $exception) {
            $result = "Result: error\n Error Message: ".$exception->getMessage()."\n Hint: ".$exception->getHint();
            $content = new TextContent(text: $result);

            return new CallToolResult([$content], true, ['result' => $result]);
        }
    }
}
