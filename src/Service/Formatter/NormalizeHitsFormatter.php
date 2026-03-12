<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Domain\Format\FormatPayload;
use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchResultSet;
use App\Service\Contracts\FormatterContract;
use App\Service\Utilities;

final class NormalizeHitsFormatter implements FormatterContract
{
    public function format(FormatPayload $payload): FormatPayload
    {
        if (!$payload->document instanceof SearchResultSet) {
            return $payload;
        }

        $resultSet = $payload->document;
        $hits = [];

        foreach ($resultSet->hits as $hit) {
            $hits[] = new SearchHit(
                id: $hit->id,
                url: $hit->url,
                title: $hit->title,
                snippet: Utilities::normalizeSummary($hit->snippet),
            );
        }

        return new FormatPayload(
            document: new SearchResultSet(
                query: $resultSet->query,
                hits: $hits,
                provider: $resultSet->provider,
                fetchedAt: $resultSet->fetchedAt,
                metadata: $resultSet->metadata,
            ),
            output: $payload->output,
            working: $payload->working,
        );
    }
}
