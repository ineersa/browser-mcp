<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Domain\Format\FormatPayload;
use App\Domain\Read\ReadDocument;
use App\Service\DTO\PageContents;
use App\Service\Utilities;

final class LinedOutputFormatter implements FormatterInterface
{
    public function format(FormatPayload $payload): FormatPayload
    {
        if (!$payload->document instanceof ReadDocument) {
            return $payload;
        }

        $body = (string) ($payload->working['body'] ?? '');
        $startLine = (int) ($payload->working['startLine'] ?? 0);
        $endLine = (int) ($payload->working['endLine'] ?? 0);
        $totalLines = (int) ($payload->working['totalLines'] ?? 0);

        $scrollbar = \sprintf('viewing lines [%d - %d] of %d', $startLine, max($startLine, $endLine - 1), max(0, $totalLines - 1));

        $page = new PageContents(
            url: '' !== $payload->document->canonicalUrl ? $payload->document->canonicalUrl : $payload->document->url,
            text: $payload->document->markdown,
            title: $payload->document->title,
            urls: $payload->document->references,
        );

        return new FormatPayload(
            document: $payload->document,
            output: Utilities::makeDisplay($page, $body, $scrollbar),
            working: $payload->working,
        );
    }
}
