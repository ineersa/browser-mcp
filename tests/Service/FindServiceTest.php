<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Config\AppConfig;
use App\Domain\Find\FindMatchMode;
use App\Domain\Read\ReadDocument;
use App\Service\Contracts\ReaderContract;
use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\FindService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class FindServiceTest extends TestCase
{
    public function testFindProducesExpectedResult(): void
    {
        $url = 'https://symfony.com/doc/current/scheduler.html';
        $markdown = "Intro\nThis section helps you configure triggers.\nOther line\nYou can also configure frequency dynamically.";

        $document = new ReadDocument(
            url: $url,
            canonicalUrl: $url,
            title: $url,
            markdown: $markdown,
            references: [],
            provider: 'searxng',
            fetchedAt: new \DateTimeImmutable(),
        );

        $reader = $this->createMock(ReaderContract::class);
        $reader->expects($this->once())->method('read')->willReturn($document);

        $service = new FindService($this->config(), $reader, new ArrayAdapter());

        $result = $service->__invoke(url: $url, query: 'configure', match: FindMatchMode::CONTAINS, contextLines: 5);

        $this->assertStringContainsString('url: "https://symfony.com/doc/current/scheduler.html"', $result);
        $this->assertStringContainsString('query: configure', $result);
        $this->assertStringContainsString('match: contains', $result);
        $this->assertStringContainsString('matches[1]{id,line,chunk}:', $result);
    }

    public function testFindUsesCacheForRepeatedCalls(): void
    {
        $url = 'https://example.com/article';
        $document = new ReadDocument(
            url: $url,
            canonicalUrl: $url,
            title: 'Article',
            markdown: "Alpha\nBeta\nGamma",
            references: [],
            provider: 'searxng',
            fetchedAt: new \DateTimeImmutable(),
        );

        $reader = $this->createMock(ReaderContract::class);
        $reader->expects($this->once())->method('read')->willReturn($document);

        $service = new FindService($this->config(), $reader, new ArrayAdapter());

        $service->__invoke(url: $url, query: 'beta', match: FindMatchMode::CONTAINS, contextLines: 5);
        $service->__invoke(url: $url, query: 'gamma', match: FindMatchMode::CONTAINS, contextLines: 5);

        $this->addToAssertionCount(1);
    }

    public function testFindRequiresUrl(): void
    {
        $backend = $this->createStub(ReaderContract::class);
        $service = new FindService($this->config(), $backend, new ArrayAdapter());

        $this->expectException(ToolUsageError::class);
        $service->__invoke(url: '', query: 'test', match: FindMatchMode::CONTAINS, contextLines: 5);
    }

    public function testFindRejectsEmptyQuery(): void
    {
        $backend = $this->createStub(ReaderContract::class);
        $service = new FindService($this->config(), $backend, new ArrayAdapter());

        $this->expectException(ToolUsageError::class);
        $this->expectExceptionMessage('Find query cannot be empty.');
        $service->__invoke(url: 'https://example.com', query: '', match: FindMatchMode::CONTAINS, contextLines: 5);
    }

    public function testFindProvidesNextStepsWhenNoMatches(): void
    {
        $url = 'https://example.com/page';
        $document = new ReadDocument(
            url: $url,
            canonicalUrl: $url,
            title: 'Example Page',
            markdown: "Intro\nMore content",
            references: [],
            provider: 'searxng',
            fetchedAt: new \DateTimeImmutable(),
        );

        $backend = $this->createMock(ReaderContract::class);
        $backend->expects($this->once())->method('read')->willReturn($document);

        $service = new FindService($this->config(), $backend, new ArrayAdapter());

        $output = $service->__invoke($url, 'missing', FindMatchMode::CONTAINS, 5);

        $this->assertStringContainsString('query: missing', $output);
        $this->assertStringContainsString('matches[0]:', $output);
    }

    public function testFindWrapsReaderErrorsAsBackendError(): void
    {
        $backend = $this->createMock(ReaderContract::class);
        $backend->expects($this->once())->method('read')->willThrowException(new \RuntimeException('network timeout'));

        $service = new FindService($this->config(), $backend, new ArrayAdapter());

        $this->expectException(BackendError::class);
        $service->__invoke('https://example.com', 'test', FindMatchMode::CONTAINS, 5);
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
