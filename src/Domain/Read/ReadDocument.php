<?php

declare(strict_types=1);

namespace App\Domain\Read;

use App\Service\DTO\PageContents;

final readonly class ReadDocument
{
    /**
     * @param array<string,string> $references
     * @param array<string,mixed>  $metadata
     */
    public function __construct(
        public string $url,
        public string $canonicalUrl,
        public string $title,
        public string $markdown,
        public array $references,
        public string $provider,
        public \DateTimeImmutable $fetchedAt,
        public array $metadata = [],
    ) {
    }

    public function toPageContents(): PageContents
    {
        return new PageContents(
            url: '' !== $this->canonicalUrl ? $this->canonicalUrl : $this->url,
            text: $this->markdown,
            title: $this->title,
            urls: $this->references,
        );
    }
}
