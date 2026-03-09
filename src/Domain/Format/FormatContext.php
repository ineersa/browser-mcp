<?php

declare(strict_types=1);

namespace App\Domain\Format;

final readonly class FormatContext
{
    public function __construct(
        public string $tool,
        public ?int $startLine = null,
        public ?int $numberOfLines = null,
        public bool $fetchAll = false,
        public ?string $regex = null,
        public ?int $viewTokens = null,
        public ?string $encodingName = null,
    ) {
    }
}
