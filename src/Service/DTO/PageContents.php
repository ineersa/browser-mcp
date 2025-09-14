<?php

declare(strict_types=1);

namespace App\Service\DTO;

final readonly class PageContents
{
    /**
     * @param array<string,string>       $urls
     * @param array<string,Extract>|null $snippets
     */
    public function __construct(
        public string $url,
        public string $text,
        public string $title,
        public array $urls = [],
        public ?array $snippets = null,
        public ?string $errorMessage = null,
    ) {
    }
}
