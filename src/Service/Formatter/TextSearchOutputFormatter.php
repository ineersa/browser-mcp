<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Domain\Format\FormatContext;
use App\Domain\Search\SearchResultSet;
use App\Service\DTO\PageContents;

final readonly class TextSearchOutputFormatter implements FormatterInterface
{
    public function format(FormatContext $context): FormatContext
    {
        if (!$context->document instanceof SearchResultSet) {
            return $context;
        }

        $resultSet = $context->document;
        $references = [];
        $lines = [sprintf('Search results for "%s"', $resultSet->query), ''];

        foreach ($resultSet->hits as $index => $hit) {
            $position = $index + 1;
            $references[(string) $position] = $hit->url;

            $title = '' !== trim($hit->title) ? $hit->title : $hit->url;
            $label = sprintf('%d. %s', $position, $title);
            if ('' !== trim($hit->sourceDomain)) {
                $label .= sprintf(' — %s', $hit->sourceDomain);
            }

            $lines[] = $label;
            $lines[] = sprintf('   URL: %s', $hit->url);
            if ('' !== trim($hit->snippet)) {
                $lines[] = sprintf('   Summary: %s', $hit->snippet);
            }
            $lines[] = '';
        }

        if (empty($references)) {
            $lines[] = 'No results found.';
        }

        return $context->withDocument(new PageContents(
            url: '',
            text: rtrim(implode("\n", $lines)),
            title: $resultSet->query,
            urls: $references,
        ));
    }
}
