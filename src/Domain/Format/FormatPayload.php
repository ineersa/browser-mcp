<?php

declare(strict_types=1);

namespace App\Domain\Format;

final readonly class FormatPayload
{
    /**
     * @param array<string,mixed> $working
     */
    public function __construct(
        public mixed $document,
        public string $output = '',
        public array $working = [],
    ) {
    }
}
