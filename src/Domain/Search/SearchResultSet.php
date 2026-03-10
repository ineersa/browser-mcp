<?php

declare(strict_types=1);

namespace App\Domain\Search;

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
}
