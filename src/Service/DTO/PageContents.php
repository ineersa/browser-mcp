<?php

declare(strict_types=1);

namespace App\Service\DTO;

final readonly class PageContents
{
    /**
     * @param array<string,string> $urls
     */
    public function __construct(
        public string $url,
        public string $text,
        public string $title,
        public array $urls = [],
    ) {
    }
}
