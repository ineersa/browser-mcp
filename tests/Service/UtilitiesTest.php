<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DTO\PageContents;
use App\Service\Utilities;
use PHPUnit\Framework\TestCase;

final class UtilitiesTest extends TestCase
{
    public function testRunFindInPageMatchesPlainStringCaseInsensitive(): void
    {
        $page = new PageContents(
            url: 'https://example.com',
            text: "Alpha\nBeta\nGamma",
            title: 'Example',
        );

        $result = Utilities::runFindInPage(page: $page, regex: '/BETA/i', maxResults: 1, numShowLines: 1);

        $this->assertSame('Find results for regex: `/BETA/i` in `Example`', $result->title);
        $this->assertStringContainsString('Beta', $result->text);
        $this->assertArrayHasKey('0', $result->urls);
        $this->assertSame('https://example.com', $result->urls['0']); // @phpstan-ignore-line
        $this->assertNotNull($result->snippets);
        $this->assertArrayHasKey('0', $result->snippets);
        $this->assertSame('Beta', $result->snippets['0']->text); // @phpstan-ignore-line
    }

    public function testRunFindInPageSupportsRegexLiteral(): void
    {
        $page = new PageContents(
            url: 'https://example.com',
            text: "Alpha\nVersion 1.42\nGamma",
            title: 'Example',
        );

        $result = Utilities::runFindInPage(page: $page, regex: '/\\d+\\.\\d+/', maxResults: 1, numShowLines: 1);

        $this->assertSame('Find results for regex: `/\\d+\\.\\d+/` in `Example`', $result->title);
        $this->assertStringContainsString('Version 1.42', $result->text);
        $this->assertNotNull($result->snippets);
        $this->assertArrayHasKey('0', $result->snippets);
        $this->assertSame('Version 1.42', $result->snippets['0']->text); // @phpstan-ignore-line
    }

    public function testRunFindInPageReportsInvalidRegex(): void
    {
        $page = new PageContents(
            url: 'https://example.com',
            text: "Alpha\nVersion 1.42\nGamma",
            title: 'Example',
        );

        $result = Utilities::runFindInPage(page: $page, regex: '/unterminated', maxResults: 1, numShowLines: 1);

        $this->assertSame('Find results for regex: `/unterminated` in `Example`', $result->title);
        $this->assertSame(
            'Regex error for regex `/unterminated`: Invalid regex pattern or internal PCRE error',
            $result->text
        );
        $this->assertSame([], $result->urls);
        $this->assertNotNull($result->snippets);
        $this->assertSame([], $result->snippets);
    }
}
