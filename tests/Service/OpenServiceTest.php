<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Backend\BackendInterface;
use App\Service\Backend\SearxNGBackend;
use App\Service\BrowserState;
use App\Service\DTO\Extract;
use App\Service\DTO\PageContents;
use App\Service\OpenService;
use App\Service\PageDisplayService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenServiceTest extends TestCase
{
    public function testOpenFollowsLinkAndAddsNewPageToState(): void
    {
        $expectedUrl = 'https://symfony.com/doc/current/scheduler.html';
        $html = file_get_contents(__DIR__ . '/../dumps/SearxNG/open_page.html');
        $this->assertNotFalse($html, 'Failed to read HTML fixture');

        $state = new BrowserState();
        $searchUrls = ['0' => $expectedUrl];
        $searchSnippets = [
            '0' => new Extract(
                url: $expectedUrl,
                text: 'Scheduler (Symfony Docs)',
                title: '#0',
                lineIdx: null,
            ),
        ];
        $searchPage = new PageContents(
            url: '',
            text: "# Search Results\n\n  * ",
            title: 'Search Results',
            urls: $searchUrls, // @phpstan-ignore-line
            snippets: $searchSnippets, // @phpstan-ignore-line
        );
        $state->addPage($searchPage);
        // Simulate previously opened pages so the new page lands at cursor 3, matching the Python fixture.
        /** @var array<string,string> $emptyUrls */
        $emptyUrls = [];
        $state->addPage(new PageContents(url: 'https://example.com/prev1', text: 'Prev page 1', title: 'Prev 1', urls: $emptyUrls));
        $state->addPage(new PageContents(url: 'https://example.com/prev2', text: 'Prev page 2', title: 'Prev 2', urls: $emptyUrls));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($expectedUrl, $html) {
            if ('GET' !== $method || $url !== $expectedUrl) {
                throw new \RuntimeException('Unexpected request: '.$method.' '.$url);
            }

            return new MockResponse($html);
        });
        $backend = new SearxNGBackend('https://search.example', $httpClient);

        $pageDisplay = new PageDisplayService();
        $service = new OpenService($backend, $state, $pageDisplay);

        $result = $service->__invoke(0, 0);

        $this->assertSame(3, $state->getCurrentCursor(), 'New page should set the cursor to match Python fixture.');
        $currentPage = $state->getPage();
        $this->assertSame($expectedUrl, $currentPage->url, 'Open should navigate to the link target.');
        $this->assertSame('', $state->getPage(0)->url, 'Search page should remain at cursor 0.');
        $expectedResponse = (string) ($this->loadJson('open_page_response.json')['result'] ?? '');
        $this->assertSame($expectedResponse, $result, 'Rendered response should match Python fixture.');
    }

    public function testOpenScrollsCurrentPageWithoutFetching(): void
    {
        $fixture = $this->loadJson('new_page_contents.json');
        $page = $this->makePageContents($fixture['new_page'] ?? []);

        $state = new BrowserState();
        $state->addPage($page);

        $backend = $this->createMock(BackendInterface::class);
        $backend->expects($this->never())->method('fetch');

        $pageDisplay = new PageDisplayService();
        $service = new OpenService($backend, $state, $pageDisplay);

        $loc = 42;
        $output = $service->__invoke(-1, 0, $loc, 10);

        $this->assertSame(0, $state->getCurrentCursor(), 'Scroll should not create a new page entry.');
        $this->assertSame($page, $state->getPage(), 'Current page should remain the original page instance.');
        $this->assertStringContainsString('viewing lines ['.$loc.' -', $output);
    }

    public function testOpenHandlesDirectUrlString(): void
    {
        $url = 'https://symfony.com/doc/current/scheduler.html';
        $html = file_get_contents(__DIR__ . '/../dumps/SearxNG/open_page.html');
        $this->assertNotFalse($html, 'Failed to read HTML fixture');

        $state = new BrowserState();
        /** @var array<string,string> $searchUrls */
        $searchUrls = [];
        $searchPage = new PageContents(
            url: '',
            text: '# Search Results',
            title: 'Search Results',
            urls: $searchUrls,
        );
        $state->addPage($searchPage);
        /** @var array<string,string> $emptyUrls */
        $emptyUrls = [];
        $state->addPage(new PageContents(url: 'https://example.com/prev1', text: 'Prev page 1', title: 'Prev 1', urls: $emptyUrls));
        $state->addPage(new PageContents(url: 'https://example.com/prev2', text: 'Prev page 2', title: 'Prev 2', urls: $emptyUrls));

        $httpClient = new MockHttpClient(function (string $method, string $requestUrl, array $options) use ($url, $html) {
            if ('GET' !== $method || $requestUrl !== $url) {
                throw new \RuntimeException('Unexpected request: '.$method.' '.$requestUrl);
            }

            return new MockResponse($html);
        });

        $backend = new SearxNGBackend('https://search.example', $httpClient);

        $pageDisplay = new PageDisplayService();
        $service = new OpenService($backend, $state, $pageDisplay);

        $result = $service->__invoke($url);

        $expected = (string) ($this->loadJson('open_page_response.json')['result'] ?? '');
        $this->assertSame($expected, $result);
        $this->assertSame($url, $state->getPage()->url);
        $this->assertSame(3, $state->getCurrentCursor());
    }

    /**
     * @param array<string,mixed> $data
     */
    private function makePageContents(array $data): PageContents
    {
        $snippets = $this->makeExtracts($data['snippets'] ?? null);

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
            snippets: $snippets,
            errorMessage: isset($data['errorMessage']) ? (string) $data['errorMessage'] : null,
        );
    }

    /**
     * @param array<string, array<string, mixed>>|null $raw
     *
     * @return array<string, Extract>|null
     */
    private function makeExtracts(?array $raw): ?array
    {
        if (null === $raw) {
            return null;
        }

        $result = [];
        foreach ($raw as $key => $value) {
            $result[(string) $key] = new Extract(
                url: (string) ($value['url'] ?? ''),
                text: (string) ($value['text'] ?? ''),
                title: (string) ($value['title'] ?? ''),
                lineIdx: isset($value['line_idx']) ? (int) $value['line_idx'] : null,
            );
        }

        return $result;
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
