<?php

declare(strict_types=1);

namespace App\Tests\Service\Formatter;

use App\Domain\Format\FormatContext;
use App\Domain\Format\FormatPayload;
use App\Domain\Read\ReadDocument;
use App\Domain\Search\SearchResultSet;
use App\Service\Formatter\LegacyDisplayFormatterPipelineAdapter;
use App\Service\PageDisplayService;
use PHPUnit\Framework\TestCase;

final class LegacyDisplayFormatterPipelineAdapterTest extends TestCase
{
    public function testProcessFormatsReadDocumentViaLegacyPageDisplayService(): void
    {
        $document = new ReadDocument(
            url: 'https://example.com/page',
            canonicalUrl: 'https://example.com/page',
            title: 'Example Page',
            markdown: "Alpha\nBeta",
            references: [],
            provider: 'default',
            fetchedAt: new \DateTimeImmutable(),
        );

        $payload = new FormatPayload(document: $document);
        $context = new FormatContext(tool: 'open', startLine: 0, numberOfLines: -1);

        $display = new PageDisplayService();
        $pipeline = new LegacyDisplayFormatterPipelineAdapter($display);

        $result = $pipeline->process($payload, $context);
        $expected = $display->renderStandalone($document->toPageContents(), 0, -1);

        $this->assertSame($expected, $result->output);
    }

    public function testProcessFormatsSearchResultSetViaLegacyPageDisplayService(): void
    {
        $search = new SearchResultSet(
            query: 'query',
            hits: [],
            provider: 'searxng',
            fetchedAt: new \DateTimeImmutable(),
            renderedText: 'Search results for "query"',
            renderedTitle: 'query',
            references: [],
        );

        $payload = new FormatPayload(document: $search);
        $context = new FormatContext(tool: 'search', startLine: 0, numberOfLines: -1);

        $display = new PageDisplayService();
        $pipeline = new LegacyDisplayFormatterPipelineAdapter($display);

        $result = $pipeline->process($payload, $context);
        $expected = $display->renderStandalone($search->toPageContents(), 0, -1);

        $this->assertSame($expected, $result->output);
    }
}
