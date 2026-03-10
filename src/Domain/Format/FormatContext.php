<?php

declare(strict_types=1);

namespace App\Domain\Format;

final readonly class FormatContext
{
    /**
     * @param array<string,mixed> $working
     */
    public function __construct(
        public string $tool,
        public ?int $startLine = null,
        public ?int $numberOfLines = null,
        public bool $fetchAll = false,
        public ?string $regex = null,
        public ?int $viewTokens = null,
        public ?string $encodingName = null,
        public mixed $document = null,
        public string $output = '',
        public array $working = [],
    ) {
    }

    public function withOutput(string $output): self
    {
        return new self(
            tool: $this->tool,
            startLine: $this->startLine,
            numberOfLines: $this->numberOfLines,
            fetchAll: $this->fetchAll,
            regex: $this->regex,
            viewTokens: $this->viewTokens,
            encodingName: $this->encodingName,
            document: $this->document,
            output: $output,
            working: $this->working,
        );
    }

    public function withDocument(mixed $document): self
    {
        return new self(
            tool: $this->tool,
            startLine: $this->startLine,
            numberOfLines: $this->numberOfLines,
            fetchAll: $this->fetchAll,
            regex: $this->regex,
            viewTokens: $this->viewTokens,
            encodingName: $this->encodingName,
            document: $document,
            output: $this->output,
            working: $this->working,
        );
    }
}
