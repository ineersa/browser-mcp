<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Domain\Format\FormatContext;
use App\Domain\Format\FormatPayload;
use App\Domain\Read\ReadDocument;
use App\Domain\Search\SearchResultSet;
use App\Service\DTO\PageContents;
use App\Service\PageDisplayService;

final readonly class LegacyDisplayFormatterPipelineAdapter implements FormatterPipelineInterface
{
    public function __construct(
        private PageDisplayService $pageDisplay,
    ) {
    }

    public function process(FormatPayload $payload, FormatContext $context): FormatPayload
    {
        $page = $this->resolvePage($payload);
        $startLine = $context->startLine ?? 0;
        $numberOfLines = $context->fetchAll ? -1 : ($context->numberOfLines ?? -1);

        $output = $this->pageDisplay->renderStandalone(
            page: $page,
            loc: $startLine,
            numLines: $numberOfLines,
        );

        return new FormatPayload(
            document: $payload->document,
            output: $output,
            working: $payload->working,
        );
    }

    private function resolvePage(FormatPayload $payload): PageContents
    {
        return match (true) {
            $payload->document instanceof SearchResultSet => $payload->document->toPageContents(),
            $payload->document instanceof ReadDocument => $payload->document->toPageContents(),
            $payload->document instanceof PageContents => $payload->document,
            default => throw new \InvalidArgumentException(\sprintf('Unsupported document type `%s` for legacy formatter pipeline.', get_debug_type($payload->document))),
        };
    }
}
