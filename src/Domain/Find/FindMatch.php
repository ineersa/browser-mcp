<?php

declare(strict_types=1);

namespace App\Domain\Find;

final readonly class FindMatch
{
    public function __construct(
        public int $index,
        public int $lineNumber,
        public string $snippet,
    ) {
    }
}
