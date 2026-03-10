<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Domain\Format\FormatPayload;
use App\Domain\Search\SearchResultSet;
use App\Service\Utilities;

final class SearchResultToArrayFormatter implements FormatterInterface
{
    public function format(FormatPayload $payload): FormatPayload
    {
        if (!$payload->document instanceof SearchResultSet) {
            return $payload;
        }

        $rows = [];
        foreach ($payload->document->hits as $hit) {
            $rows[] = [
                'url' => $hit->url,
                'domain' => Utilities::getDomain($hit->url),
                'title' => $hit->title,
                'summary' => $hit->snippet,
            ];
        }

        return new FormatPayload(
            document: $rows,
            output: $payload->output,
            working: $payload->working,
        );
    }
}
