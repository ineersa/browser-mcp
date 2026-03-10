<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Domain\Find\FindDocument;
use App\Domain\Format\FormatPayload;
use App\Service\Contracts\FormatterContract;

final class FindResultToArrayFormatter implements FormatterContract
{
    public function format(FormatPayload $payload): FormatPayload
    {
        if (!$payload->document instanceof FindDocument) {
            return $payload;
        }

        $findDocument = $payload->document;
        $rows = [];

        foreach ($findDocument->matches as $match) {
            $rows[] = [
                'id' => $match->index,
                'line' => $match->lineNumber,
                'chunk' => $match->snippet,
            ];
        }

        $document = [
            'url' => '' !== $findDocument->readDocument->canonicalUrl
                ? $findDocument->readDocument->canonicalUrl
                : $findDocument->readDocument->url,
            'query' => $findDocument->query,
            'match' => $findDocument->match->value,
            'matches' => $rows,
        ];

        return new FormatPayload(
            document: $document,
            output: $payload->output,
            working: $payload->working,
        );
    }
}
