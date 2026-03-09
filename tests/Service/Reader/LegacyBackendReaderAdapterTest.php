<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Domain\Read\ReadRequest;
use App\Service\Backend\BackendInterface;
use App\Service\DTO\PageContents;
use App\Service\Reader\LegacyBackendReaderAdapter;
use PHPUnit\Framework\TestCase;

final class LegacyBackendReaderAdapterTest extends TestCase
{
    public function testReadMapsLegacyBackendPageToReadDocument(): void
    {
        $url = 'https://example.com/article';
        $page = new PageContents(
            url: $url,
            text: "Article body\nSecond line",
            title: 'Article',
            urls: ['ref-0' => 'https://example.com/ref'],
        );

        $backend = $this->createMock(BackendInterface::class);
        $backend->expects($this->once())
            ->method('fetch')
            ->with($url)
            ->willReturn($page);

        $adapter = new LegacyBackendReaderAdapter($backend, 'default');
        $result = $adapter->read(new ReadRequest(url: $url, canonicalUrl: $url));

        $this->assertSame($url, $result->url);
        $this->assertSame($url, $result->canonicalUrl);
        $this->assertSame('default', $result->provider);
        $this->assertSame($page->title, $result->title);
        $this->assertSame($page->text, $result->markdown);
        $this->assertSame($page->urls, $result->references);

        $converted = $result->toPageContents();
        $this->assertSame($page->url, $converted->url);
        $this->assertSame($page->text, $converted->text);
        $this->assertSame($page->title, $converted->title);
        $this->assertSame($page->urls, $converted->urls);
    }
}
