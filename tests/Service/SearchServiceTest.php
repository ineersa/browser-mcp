<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Backend\BackendInterface;
use App\Service\BrowserState;
use App\Service\DTO\PageContents;
use App\Service\Exception\ToolUsageError;
use App\Service\PageDisplayService;
use App\Service\SearchService;
use PHPUnit\Framework\TestCase;

final class SearchServiceTest extends TestCase
{
    public function testInvokeReturnsRenderedStandaloneOutput(): void
    {
        $page = new PageContents(
            url: '',
            text: "Search results for \"foo\"\n\n1. Example — example.com\n   URL: https://example.com/article\n   Summary: Example summary.",
            title: 'foo',
            urls: [],
        );

        $backend = $this->createStub(BackendInterface::class);
        $backend->method('search')->willReturn($page);

        $state = new BrowserState();
        $state->addPage(new PageContents(
            url: 'https://example.com/prev',
            text: 'old',
            title: 'old',
            urls: [],
        ));

        $display = new PageDisplayService();
        $service = new SearchService($backend, $state, $display);

        $expected = $display->renderStandalone($page);
        $result = $service('foo', 5);

        $this->assertSame($expected, $result);
        $this->assertTrue($state->isEmpty(), 'Search should reset BrowserState cache.');
    }

    public function testInvokeLeavesStateEmptyWhenDisplayFails(): void
    {
        $backend = $this->createMock(BackendInterface::class);
        $backend->expects($this->once())->method('search')->willReturn(
            new PageContents(url: '', text: 'Search results content', title: 'query', urls: [])
        );

        $state = new BrowserState();
        $display = $this->createMock(PageDisplayService::class);
        $display->expects($this->once())
            ->method('renderStandalone')
            ->willThrowException(new ToolUsageError('render failed'));

        $service = new SearchService($backend, $state, $display);

        try {
            $service('query');
            $this->fail('SearchService should rethrow ToolUsageError from PageDisplayService');
        } catch (ToolUsageError $e) {
            $this->assertSame('render failed', $e->getMessage());
            $this->assertTrue($state->isEmpty(), 'BrowserState should be empty even when rendering fails.');
        }
    }
}
