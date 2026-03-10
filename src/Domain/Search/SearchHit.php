<?php

declare(strict_types=1);

namespace App\Domain\Search;

final readonly class SearchHit
{
    public function __construct(
        public string $id,
        public string $url,
        public string $title,
        public string $snippet = '',
    ) {
    }
}
