<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Config\AppConfig;
use App\Domain\Read\ReadDocument;
use App\Service\Contracts\ReaderContract;
use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\OpenService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\ItemInterface;

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

        $reader = $this->createMock(ReaderContract::class);
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

        $reader = $this->createMock(ReaderContract::class);
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

        $reader = $this->createStub(ReaderContract::class);
        $reader->method('read')->willReturn($document);

        $service = new OpenService($this->config(), $reader, new ArrayAdapter());

        $this->expectException(ToolUsageError::class);
        $service->__invoke($url, 5, 1);
    }

    public function testOpenWrapsReaderErrorsAsBackendError(): void
    {
        $articleUrl = 'https://example.com/article';

        $reader = $this->createMock(ReaderContract::class);
        $reader->expects($this->once())
            ->method('read')
            ->willThrowException(new \RuntimeException('network timeout'));

        $service = new OpenService($this->config(), $reader, new ArrayAdapter());

        $this->expectException(BackendError::class);
        $service->__invoke($articleUrl, 0, 50);
    }

    public function testOpenAutoSelectsSnippetWindowWhenStartAtLineMissing(): void
    {
        $url = 'https://example.com/article';
        $lines = [];
        for ($i = 0; $i < 140; ++$i) {
            $lines[] = 'Line '.$i;
        }
        $lines[80] = 'General relativity describes gravity as geometry of spacetime.';

        $document = new ReadDocument(
            url: $url,
            canonicalUrl: $url,
            title: 'Article',
            markdown: implode("\n", $lines),
            references: [],
            provider: 'searxng',
            fetchedAt: new \DateTimeImmutable(),
        );

        $cache = new ArrayAdapter();
        $snippetKey = 'search_snippets.'.hash('sha256', $url);
        $cache->get($snippetKey, static function (ItemInterface $item): array {
            $item->expiresAfter(600);

            return ['gravity as geometry of spacetime'];
        });

        $reader = $this->createStub(ReaderContract::class);
        $reader->method('read')->willReturn($document);

        $service = new OpenService($this->config(), $reader, $cache);
        $output = $service->__invoke($url);

        $this->assertStringContainsString('viewing lines [70 - 120] of 139', $output);
    }

    public function testOpenAutoFallsBackToTopWindowWhenSnippetMissing(): void
    {
        $url = 'https://example.com/article';
        $lines = [];
        for ($i = 0; $i < 160; ++$i) {
            $lines[] = 'Line '.$i;
        }

        $document = new ReadDocument(
            url: $url,
            canonicalUrl: $url,
            title: 'Article',
            markdown: implode("\n", $lines),
            references: [],
            provider: 'searxng',
            fetchedAt: new \DateTimeImmutable(),
        );

        $reader = $this->createStub(ReaderContract::class);
        $reader->method('read')->willReturn($document);

        $service = new OpenService($this->config(), $reader, new ArrayAdapter());
        $output = $service->__invoke($url);

        $this->assertStringContainsString('viewing lines [0 - 99] of 159', $output);
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
