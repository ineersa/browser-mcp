<?php

declare(strict_types=1);

namespace App\Domain\Read;

final readonly class ReadRequest
{
    public function __construct(
        public string $url,
        public string $canonicalUrl = '',
    ) {
    }
}
