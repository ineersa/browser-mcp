<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Config\AppConfig;
use App\Domain\Read\ReadDocument;
use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\OpenService;
use App\Service\Reader\ReaderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class OpenServiceTest extends TestCase
{
    public function testOpenFetchesDocumentAndCachesByUrl(): void
    {
        $expectedUrl = 'https://example.com/article';
        $document = new ReadDocument(
            url: $expectedUrl,
            canonicalUrl: $expectedUrl,
            title: 'Example Article',
            markdown: "Line one\nLine two\nLine three",
            references: [],
            provider: 'searxng',
            fetchedAt: new \DateTimeImmutable(),
        );

        $reader = $this->createMock(ReaderInterface::class);
        $reader->expects($this->once())->method('read')->willReturn($document);

        $service = new OpenService($this->config(), $reader, new ArrayAdapter());

        $result = $service->__invoke($expectedUrl, 0, 2);
        $service->__invoke($expectedUrl, 0, 2);

        $this->assertStringContainsString('Example Article (example.com)', $result);
        $this->assertStringContainsString('viewing lines [0 - 1] of 2', $result);
    }

    public function testOpenSupportsFetchAll(): void
    {
        $url = 'https://example.com/page';
        $document = new ReadDocument(
            url: $url,
            canonicalUrl: $url,
            title: 'Page',
            markdown: "First\nSecond\nThird",
            references: [],
            provider: 'searxng',
            fetchedAt: new \DateTimeImmutable(),
        );

        $reader = $this->createMock(ReaderInterface::class);
        $reader->expects($this->once())->method('read')->willReturn($document);

        $service = new OpenService($this->config(), $reader, new ArrayAdapter());

        $output = $service->__invoke($url, 0, 1, true);

        $this->assertStringContainsString('viewing lines [0 - 2] of 2', $output);
        $this->assertStringContainsString('L2: Third', $output);
    }

    public function testOpenThrowsWhenStartLineExceedsPage(): void
    {
        $url = 'https://example.com/page';
        $document = new ReadDocument(
            url: $url,
            canonicalUrl: $url,
            title: 'Page',
            markdown: "First\nSecond",
            references: [],
            provider: 'searxng',
            fetchedAt: new \DateTimeImmutable(),
        );

        $reader = $this->createStub(ReaderInterface::class);
        $reader->method('read')->willReturn($document);

        $service = new OpenService($this->config(), $reader, new ArrayAdapter());

        $this->expectException(ToolUsageError::class);
        $service->__invoke($url, 5, 1);
    }

    public function testOpenWrapsReaderErrorsAsBackendError(): void
    {
        $articleUrl = 'https://example.com/article';

        $reader = $this->createMock(ReaderInterface::class);
        $reader->expects($this->once())
            ->method('read')
            ->willThrowException(new \RuntimeException('network timeout'));

        $service = new OpenService($this->config(), $reader, new ArrayAdapter());

        $this->expectException(BackendError::class);
        $service->__invoke($articleUrl, 0, 50);
}

    private function config(): AppConfig
    {
        return new AppConfig([
            'general' => [
                'open_cache_ttl_seconds' => 300,
                'find_cache_ttl_seconds' => 300,
            ],
        ]);
    }
}
