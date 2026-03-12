<?php

declare(strict_types=1);

namespace App\Domain\Find;

use App\Domain\Read\ReadDocument;

final readonly class FindDocument
{
    /**
     * @param list<FindMatch> $matches
     */
    public function __construct(
        public ReadDocument $readDocument,
        public string $query,
        public FindMatchMode $match,
        public array $matches,
    ) {
    }
}
