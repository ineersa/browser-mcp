<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Domain\Find\FindDocument;
use App\Domain\Format\FormatPayload;
use App\Service\Utilities;

final class FindOutputFormatter implements FormatterInterface
{
    public function format(FormatPayload $payload): FormatPayload
    {
        if (!$payload->document instanceof FindDocument) {
            return $payload;
        }

        $findDocument = $payload->document;
        $chunks = [];

        foreach ($findDocument->matches as $match) {
            $chunks[] = sprintf('# 【%d†match at L%d】', $match->index, $match->lineNumber)."\n".$match->snippet;
        }

        $displayText = !empty($chunks)
            ? implode("\n\n", $chunks)
            : sprintf(
                "Pattern not found for query: `%s` (match: `%s`)\n\nNo visible matches were found on this page. The content may be structured data (e.g. JSON) or outside the scanned range. Adjust the query if appropriate or inspect the page manually.\n\nNext steps: use `browser.open` to review the surrounding page manually, or refine the query before responding.",
                $findDocument->query,
                $findDocument->match->value,
            );

        $canonicalUrl = $findDocument->readDocument->canonicalUrl;
        $title = sprintf('Find results for %s `%s` in `%s`', $findDocument->match->value, $findDocument->query, $findDocument->readDocument->title);
        $header = $title;
        $domain = Utilities::getDomain($canonicalUrl);
        if ('' !== $domain) {
            $header .= sprintf(' (%s)', $domain);
        }
        if ('' !== $canonicalUrl) {
            $header .= sprintf("\nURL: %s", $canonicalUrl);
        }

        $lines = Utilities::wrapLines($displayText);
        while (!empty($lines) && '' === $lines[count($lines) - 1]) {
            array_pop($lines);
        }

        $body = Utilities::joinLines($lines, true, 0);
        $output = $header."\n\n".$body;

        return new FormatPayload(
            document: $findDocument,
            output: $output,
            working: $payload->working,
        );
    }
}
