<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DTO\PageContents;
use App\Service\Utilities;
use PHPUnit\Framework\TestCase;

final class UtilitiesTest extends TestCase
{
    public function testCanonicalizeUrlAddsSchemeAndNormalizesHost(): void
    {
        $canonical = Utilities::canonicalizeUrl('Example.COM/docs');

        $this->assertSame('https://example.com/docs', $canonical);
    }

    public function testWrapLinesPreservesEmptyLines(): void
    {
        $lines = Utilities::wrapLines("Alpha\n\nBeta", 80);

        $this->assertSame(['Alpha', '', 'Beta'], $lines);
    }

    public function testMakeDisplayIncludesHeaderAndReferences(): void
    {
        $page = new PageContents(
            url: 'https://example.com/article',
            text: 'Body',
            title: 'Article',
            urls: ['0' => 'https://example.com/ref'], // @phpstan-ignore-line
        );

        $display = Utilities::makeDisplay($page, 'L0: Body 【0†ref】', 'viewing lines [0 - 0] of 0');

        $this->assertStringContainsString('Article (example.com)', $display);
        $this->assertStringContainsString('URL: https://example.com/article', $display);
        $this->assertStringContainsString('References:', $display);
    }
}
