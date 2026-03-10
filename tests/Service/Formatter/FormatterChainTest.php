<?php

declare(strict_types=1);

namespace App\Tests\Service\Formatter;

use App\Domain\Format\FormatPayload;
use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchResultSet;
use App\Service\Formatter\FormatterChain;
use App\Service\Formatter\FormatterInterface;
use App\Service\Formatter\NormalizeHitsFormatter;
use App\Service\Formatter\SearchResultToArrayFormatter;
use App\Service\Formatter\ToonFormatter;
use App\Service\Utilities;
use HelgeSverre\Toon\Toon;
use PHPUnit\Framework\TestCase;

final class FormatterChainTest extends TestCase
{
    public function testNormalizeHitsFormatterNormalizesSummaries(): void
    {
        $search = new SearchResultSet(
            query: 'query',
            hits: [
                new SearchHit(
                    id: '1',
                    url: 'https://example.com/page',
                    title: 'Example',
                    snippet: "  Hello\n\tworld  ",
                ),
            ],
            provider: 'searxng',
            fetchedAt: new \DateTimeImmutable(),
        );

        $formatted = (new NormalizeHitsFormatter())->format(new FormatPayload(document: $search));

        $this->assertInstanceOf(SearchResultSet::class, $formatted->document);
        $this->assertSame('Hello world', $formatted->document->hits[0]->snippet);
        $this->assertSame('Hello world', Utilities::normalizeSummary("  Hello\n\tworld  "));
    }

    public function testSearchResultToArrayFormatterBuildsList(): void
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

        $formatted = (new SearchResultToArrayFormatter())->format(new FormatPayload(document: $search));

        $this->assertSame([
            [
                'url' => 'https://example.com/page',
                'domain' => 'example.com',
                'title' => 'Example Result',
                'summary' => 'Example snippet',
            ],
        ], $formatted->document);
    }

    public function testFormatRunsFormattersInOrderTheyWereAdded(): void
    {
        $chain = new FormatterChain();
        $chain->addFormatter(new class() implements FormatterInterface {
            public function format(FormatPayload $payload): FormatPayload
            {
                return new FormatPayload(document: $payload->document, output: $payload->output.'A');
            }
        });
        $chain->addFormatter(new class() implements FormatterInterface {
            public function format(FormatPayload $payload): FormatPayload
            {
                return new FormatPayload(document: $payload->document, output: $payload->output.'B');
            }
        });
        $chain->addFormatter(new class() implements FormatterInterface {
            public function format(FormatPayload $payload): FormatPayload
            {
                return new FormatPayload(document: $payload->document, output: $payload->output.'C');
            }
        });

        $result = $chain->format(new FormatPayload(document: []));

        $this->assertSame('ABC', $result->output);
    }

    public function testToonFormatterEncodesArrayDocument(): void
    {
        $document = [['a' => 1], ['b' => 2]];
        $result = (new ToonFormatter())->format(new FormatPayload(document: $document));

        $this->assertSame(Toon::encode($document), $result->output);
    }
}
