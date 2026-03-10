<?php

declare(strict_types=1);

namespace App\Tests\Service\Formatter;

use App\Domain\Format\FormatContext;
use App\Domain\Read\ReadDocument;
use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchResultSet;
use App\Service\Formatter\FormatterChain;
use App\Service\Formatter\FormatterInterface;
use App\Service\Formatter\LegacyDisplayFormatterPipelineAdapter;
use App\Service\Formatter\TextSearchOutputFormatter;
use App\Service\PageDisplayService;
use PHPUnit\Framework\TestCase;

final class LegacyDisplayFormatterPipelineAdapterTest extends TestCase
{
    public function testFormatFormatsReadDocumentViaLegacyPageDisplayService(): void
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

        $context = new FormatContext(tool: 'open', startLine: 0, numberOfLines: -1, document: $document);

        $display = new PageDisplayService();
        $chain = new FormatterChain();
        $chain->addFormatter(new LegacyDisplayFormatterPipelineAdapter($display));

        $result = $chain->format($context);
        $expected = $display->renderStandalone($document->toPageContents(), 0, -1);

        $this->assertSame($expected, $result->output);
    }

    public function testFormatFormatsSearchResultSetViaLegacyPageDisplayService(): void
    {
        $search = new SearchResultSet(
            query: 'query',
            hits: [
                new SearchHit(
                    id: '0',
                    url: 'https://example.com/page',
                    title: 'Example Result',
                    snippet: 'Example snippet',
                ),
            ],
            provider: 'searxng',
            fetchedAt: new \DateTimeImmutable(),
        );

        $context = new FormatContext(tool: 'search', startLine: 0, numberOfLines: -1, document: $search);

        $display = new PageDisplayService();
        $chain = new FormatterChain();
        $chain->addFormatter(new TextSearchOutputFormatter());
        $chain->addFormatter(new LegacyDisplayFormatterPipelineAdapter($display));

        $result = $chain->format($context);
        $asPage = (new TextSearchOutputFormatter())->format(new FormatContext(tool: 'search', document: $search));
        $expected = $display->renderStandalone($asPage->document, 0, -1);

        $this->assertSame($expected, $result->output);
    }

    public function testFormatRunsFormattersInOrderTheyWereAdded(): void
    {
        $chain = new FormatterChain();
        $chain->addFormatter(new class() implements FormatterInterface {
            public function format(FormatContext $context): FormatContext
            {
                return $context->withOutput($context->output.'A');
            }
        });
        $chain->addFormatter(new class() implements FormatterInterface {
            public function format(FormatContext $context): FormatContext
            {
                return $context->withOutput($context->output.'B');
            }
        });
        $chain->addFormatter(new class() implements FormatterInterface {
            public function format(FormatContext $context): FormatContext
            {
                return $context->withOutput($context->output.'C');
            }
        });

        $result = $chain->format(new FormatContext(tool: 'open'));

        $this->assertSame('ABC', $result->output);
    }
}
