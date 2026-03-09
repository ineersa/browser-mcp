<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Domain\Format\FormatContext;
use App\Domain\Read\ReadDocument;
use App\Domain\Search\SearchResultSet;
use App\Service\DTO\PageContents;
use App\Service\PageDisplayService;

final readonly class LegacyDisplayFormatterPipelineAdapter implements FormatterInterface
{
    public function __construct(
        private PageDisplayService $pageDisplay,
    ) {
    }

    public function format(FormatContext $context): FormatContext
    {
        $page = $this->resolvePage($context->document);
        $startLine = $context->startLine ?? 0;
        $numberOfLines = $context->fetchAll ? -1 : ($context->numberOfLines ?? -1);

        $output = $this->pageDisplay->renderStandalone(
            page: $page,
            loc: $startLine,
            numLines: $numberOfLines,
        );

        return $context->withOutput($output);
    }

    private function resolvePage(mixed $document): PageContents
    {
        return match (true) {
            $document instanceof SearchResultSet => $document->toPageContents(),
            $document instanceof ReadDocument => $document->toPageContents(),
            $document instanceof PageContents => $document,
            default => throw new \InvalidArgumentException(\sprintf('Unsupported document type `%s` for legacy formatter pipeline.', get_debug_type($document))),
        };
    }
}
