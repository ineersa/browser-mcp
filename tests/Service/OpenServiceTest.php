<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BrowserState;
use App\Service\DTO\PageContents;
use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\OpenService;
use App\Service\PageDisplayService;
use App\Service\Reader\ReaderInterface;
use App\Service\Reader\SearxNGReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenServiceTest extends TestCase
{
    public function testOpenFetchesPageAndCachesByUrl(): void
    {
        $expectedUrl = 'https://raw.usercontent.com/cbracco/html5-test-page/refs/heads/master/index.html';
        $html = file_get_contents(__DIR__.'/../dumps/SearxNG/open_page.html');
        $this->assertNotFalse($html, 'Failed to read HTML fixture');

        $httpClient = new MockHttpClient(static function (string $method, string $url) use ($expectedUrl, $html) {
            if ('GET' !== $method || $url !== $expectedUrl) {
                throw new \RuntimeException('Unexpected request: '.$method.' '.$url);
            }

            return new MockResponse($html);
        });
        $reader = new SearxNGReader($httpClient);

        $state = new BrowserState();
        $display = new PageDisplayService();
        $service = new OpenService($reader, $state, $display);

        $result = $service->__invoke($expectedUrl, 0, 50);

        $expectedResponse = (string) ($this->loadJson('open_page_response.json')['result'] ?? '');
        $this->assertSame($expectedResponse, $result);
        $this->assertNotNull($state->getPageByUrl($expectedUrl));
        $this->assertSame($expectedUrl, $state->getCurrentUrl());
    }

    public function testOpenUsesCachedPageWithoutFetching(): void
    {
        $fixture = $this->loadJson('new_page_contents.json');
        $page = $this->makePageContents($fixture['new_page'] ?? []);

        $state = new BrowserState();
        $state->addPage($page);

        $reader = $this->createMock(ReaderInterface::class);
        $reader->expects($this->never())->method('read');

        $display = new PageDisplayService();
        $service = new OpenService($reader, $state, $display);

        $startLine = 42;
        $output = $service->__invoke($page->url, $startLine, 10);

        $this->assertStringContainsString('viewing lines ['.$startLine.' -', $output);
        $this->assertSame($page, $state->getPageByUrl($page->url));
    }

    /**
     * @throws BackendError
     */
    public function testOpenRemovesNewPageWhenDisplayFails(): void
    {
        $articleUrl = 'https://example.com/article';
        $articlePage = new PageContents(
            url: $articleUrl,
            text: "Article content\nline two",
            title: 'Article',
            urls: [],
        );

        $reader = $this->createMock(ReaderInterface::class);
        $reader->expects($this->once())->method('read')->willReturn(new \App\Domain\Read\ReadDocument(
            url: $articleUrl,
            canonicalUrl: $articleUrl,
            title: $articlePage->title,
            markdown: $articlePage->text,
            references: $articlePage->urls,
            provider: 'searxng',
            fetchedAt: new \DateTimeImmutable(),
        ));

        $state = new BrowserState();

        $display = $this->createMock(PageDisplayService::class);
        $display->expects($this->once())
            ->method('showPage')
            ->willThrowException(new ToolUsageError('cannot display article'));

        $service = new OpenService($reader, $state, $display);

        try {
            $service->__invoke($articleUrl, 0, 50);
            $this->fail('OpenService should rethrow ToolUsageError from PageDisplayService');
        } catch (ToolUsageError $e) {
            $this->assertSame('cannot display article', $e->getMessage());
            $this->assertNull($state->getPageByUrl($articleUrl));
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function makePageContents(array $data): PageContents
    {
        /** @var array<string,string> $urls */
        $urls = [];
        if (isset($data['urls']) && \is_array($data['urls'])) {
            foreach ($data['urls'] as $key => $value) {
                $urls[(string) $key] = (string) $value;
            }
        }

        return new PageContents(
            url: (string) ($data['url'] ?? ''),
            text: (string) ($data['text'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            urls: $urls,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function loadJson(string $filename): array
    {
        $path = __DIR__.'/../dumps/SearxNG/'.$filename;
        $raw = file_get_contents($path);
        if (false === $raw) {
            $this->fail('Failed to read fixture '.$filename);
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            $this->fail('Fixture is not valid JSON: '.$filename);
        }

        return $decoded;
    }
}
