<?php

declare(strict_types=1);

namespace App\Domain\Search;

use App\Service\DTO\PageContents;

final readonly class SearchResultSet
{
    /**
     * @param list<SearchHit>      $hits
     * @param array<string,string> $references
     * @param array<string,mixed>  $metadata
     */
    public function __construct(
        public string $query,
        public array $hits,
        public string $provider,
        public \DateTimeImmutable $fetchedAt,
        public string $renderedText,
        public string $renderedTitle,
        public array $references = [],
        public array $metadata = [],
    ) {
    }

    public function toPageContents(): PageContents
    {
        return new PageContents(
            url: '',
            text: $this->renderedText,
            title: '' !== $this->renderedTitle ? $this->renderedTitle : $this->query,
            urls: $this->references,
        );
    }
}
