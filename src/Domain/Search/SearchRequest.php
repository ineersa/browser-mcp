<?php

declare(strict_types=1);

namespace App\Domain\Search;

final readonly class SearchRequest
{
    public function __construct(
        public string $query,
        public int $limit = 5,
        public ?string $provider = null,
    ) {
    }
}
