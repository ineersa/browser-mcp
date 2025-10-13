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
    public const string DESCRIPTION = 'Loads `url` and returns a window of the page text. Provide `start_at_line` (0-based) and `number_of_lines` (how many lines to show, typically 50). Pages are cached by URL so you can scroll or run `find` without re-fetching. The response ends with a References section that lists canonical URLs for any inline citations.';

    public function __construct(
        private readonly OpenService $openService,
    ) {
    }

    public function __invoke(
        string $url,
        int $start_at_line,
        int $number_of_lines,
    ): CallToolResult {
        if ('' === trim($url)) {
            throw new ToolUsageError('Invalid URL provided.')->setHint('Use an absolute URL such as `https://example.com/article`.');
        }

        if ($start_at_line < 0) {
            throw new ToolUsageError('`start_at_line` must be zero or greater.')->setHint('Use 0 for the top of the page.');
        }

        if ($number_of_lines <= 0) {
            throw new ToolUsageError('`number_of_lines` must be greater than zero.')->setHint('Pick how many lines to display, e.g. 50.');
        }

        try {
            $result = $this->openService->__invoke($url, $start_at_line, $number_of_lines);

            $content = new TextContent($result);

            return new CallToolResult([$content], null, false);
        } catch (ToolUsageError|BackendError $exception) {
            $result = "Result: error\n Error Message: ".$exception->getMessage()."\n Hint: ".$exception->getHint();
            $content = new TextContent(text: $result);

            return new CallToolResult([$content], null, true);
        }
    }
}
