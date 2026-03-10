<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Domain\Find\FindMatchMode;
use App\Domain\Read\ReadDocument;
use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\FindService;
use App\Service\Reader\ReaderInterface;
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

        $reader = $this->createMock(ReaderInterface::class);
        $reader->expects($this->once())->method('read')->willReturn($document);

        $service = new FindService($reader, new ArrayAdapter(), 300);

        $result = $service->__invoke(url: $url, query: 'configure', match: FindMatchMode::CONTAINS, contextLines: 5);

        $this->assertStringContainsString('Find results for contains `configure`', $result);
        $this->assertStringContainsString('URL: https://symfony.com/doc/current/scheduler.html', $result);
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

        $reader = $this->createMock(ReaderInterface::class);
        $reader->expects($this->once())->method('read')->willReturn($document);

        $service = new FindService($reader, new ArrayAdapter(), 300);

        $service->__invoke(url: $url, query: 'beta', match: FindMatchMode::CONTAINS, contextLines: 5);
        $service->__invoke(url: $url, query: 'gamma', match: FindMatchMode::CONTAINS, contextLines: 5);

        $this->addToAssertionCount(1);
    }

    public function testFindRequiresUrl(): void
    {
        $backend = $this->createStub(ReaderInterface::class);
        $service = new FindService($backend, new ArrayAdapter(), 300);

        $this->expectException(ToolUsageError::class);
        $service->__invoke(url: '', query: 'test', match: FindMatchMode::CONTAINS, contextLines: 5);
    }

    public function testFindRejectsEmptyQuery(): void
    {
        $backend = $this->createStub(ReaderInterface::class);
        $service = new FindService($backend, new ArrayAdapter(), 300);

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

        $backend = $this->createMock(ReaderInterface::class);
        $backend->expects($this->once())->method('read')->willReturn($document);

        $service = new FindService($backend, new ArrayAdapter(), 300);

        $output = $service->__invoke($url, 'missing', FindMatchMode::CONTAINS, 5);

        $this->assertStringContainsString('Pattern not found for query: `missing` (match: `contains`)', $output);
        $this->assertStringContainsString('Next steps:', $output);
        $this->assertStringContainsString('browser.open', $output);
    }

    public function testFindWrapsReaderErrorsAsBackendError(): void
    {
        $backend = $this->createMock(ReaderInterface::class);
        $backend->expects($this->once())->method('read')->willThrowException(new \RuntimeException('network timeout'));

        $service = new FindService($backend, new ArrayAdapter(), 300);

        $this->expectException(BackendError::class);
        $service->__invoke('https://example.com', 'test', FindMatchMode::CONTAINS, 5);
    }

}
