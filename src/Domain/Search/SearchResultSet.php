<?php

declare(strict_types=1);

namespace App\Domain\Search;

use App\Service\DTO\PageContents;

final readonly class SearchResultSet
{
    /**
     * @param list<SearchHit>     $hits
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $query,
        public array $hits,
        public string $provider,
        public \DateTimeImmutable $fetchedAt,
        public array $metadata = [],
    ) {
    }

    public function toPageContents(): PageContents
    {
        $references = [];
        $resultLines = [];

        foreach ($this->hits as $index => $hit) {
            $id = '' !== $hit->id ? $hit->id : (string) ($index + 1);
            $references[$id] = $hit->url;

            $title = '' !== trim($hit->title) ? $hit->title : $hit->url;
            $resultLines[] = sprintf('%d. %s', $index + 1, $title);
            $resultLines[] = sprintf('   URL: %s', $hit->url);

            if ('' !== trim($hit->snippet)) {
                $resultLines[] = sprintf('   %s', $hit->snippet);
            }
        }

        $body = empty($resultLines)
            ? sprintf('No results found for "%s"', $this->query)
            : sprintf("Search results for \"%s\"\n\n%s", $this->query, implode("\n", $resultLines));

        return new PageContents(
            url: '',
            text: $body,
            title: $this->query,
            urls: $references,
        );
    }
}
