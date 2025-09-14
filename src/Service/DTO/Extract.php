<?php

declare(strict_types=1);

namespace App\Service\DTO;

final class Extract
{
    public function __construct(
        public string $url,
        public string $text,
        public string $title,
        public ?int $lineIdx = null,
    ) {
    }
}
