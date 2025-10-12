<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Backend\BackendInterface;
use App\Service\Backend\SearxNGBackend;
use App\Service\BrowserState;
use App\Service\DTO\PageContents;
use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\OpenService;
use App\Service\PageDisplayService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenServiceTest extends TestCase
{
    public function testOpenFetchesPageAndCachesByUrl(): void
    {
        $expectedUrl = 'https://raw.githubusercontent.com/cbracco/html5-test-page/refs/heads/master/index.html';
        $html = file_get_contents(__DIR__.'/../dumps/SearxNG/open_page.html');
        $this->assertNotFalse($html, 'Failed to read HTML fixture');

        $httpClient = new MockHttpClient(static function (string $method, string $url) use ($expectedUrl, $html) {
            if ('GET' !== $method || $url !== $expectedUrl) {
                throw new \RuntimeException('Unexpected request: '.$method.' '.$url);
            }

            return new MockResponse($html);
        });
        $backend = new SearxNGBackend('https://search.example', $httpClient);

        $state = new BrowserState();
        $display = new PageDisplayService();
        $service = new OpenService($backend, $state, $display);

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

        $backend = $this->createMock(BackendInterface::class);
        $backend->expects($this->never())->method('fetch');

        $display = new PageDisplayService();
        $service = new OpenService($backend, $state, $display);

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

        $backend = $this->createMock(BackendInterface::class);
        $backend->expects($this->once())->method('fetch')->with($articleUrl)->willReturn($articlePage);

        $state = new BrowserState();

        $display = $this->createMock(PageDisplayService::class);
        $display->expects($this->once())
            ->method('showPage')
            ->willThrowException(new ToolUsageError('cannot display article'));

        $service = new OpenService($backend, $state, $display);

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
