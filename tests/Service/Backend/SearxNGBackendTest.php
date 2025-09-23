<?php

declare(strict_types=1);

namespace App\Tests\Service\Backend;

use App\Service\Backend\SearxNGBackend;
use App\Service\Exception\BackendError;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SearxNGBackendTest extends TestCase
{
    public function testRequestSearchBuildsItemsFromResultsFixture(): void
    {
        $fixtures = $this->loadJson('results.json');
        $expectedItems = $this->normalizeItemsFixture($this->loadJson('items.json')); // @phpstan-ignore-line

        // Create a partial mock overriding fetchSearxResults
        $client = $this->createMock(HttpClientInterface::class);
        $backend = $this->getMockBuilder(SearxNGBackend::class)
            ->setConstructorArgs(['http://example.test', $client])
            ->onlyMethods(['fetchSearxResults'])
            ->getMock();

        $backend->method('fetchSearxResults')
            ->willReturn($fixtures);

        $items = $backend->requestSearch('query', 10);
        $normalized = $this->normalizeItemsFixture($items);

        $this->assertSame($expectedItems, $normalized);
    }

    public function testBuildSearchHtmlMatchesFixture(): void
    {
        $items = $this->normalizeItemsFixture($this->loadJson('items.json')); // @phpstan-ignore-line
        $expectedHtml = file_get_contents($this->getFixturesPath().'/html.html');
        $this->assertNotFalse($expectedHtml, 'Failed to read html.html');

        $backend = new SearxNGBackend('http://example.test', HttpClient::create());
        $html = $backend->buildSearchHtml($items);

        $this->assertSame($expectedHtml, $html);
    }

    /**
     * @throws BackendError
     */
    public function testSearchPageContentsMatchesPythonFixture(): void
    {
        $fixture = $this->loadJson('page_contents_search.json');
        $expected = $fixture['page_contents'] ?? null;
        $this->assertIsArray($expected, 'Invalid fixture: missing page_contents');

        // Prepare a backend that returns the fixture HTML from Python
        $client = $this->createMock(HttpClientInterface::class);
        $backend = $this->getMockBuilder(SearxNGBackend::class)
            ->setConstructorArgs(['http://example.test', $client])
            ->onlyMethods(['requestSearch', 'buildSearchHtml'])
            ->getMock();

        // Avoid hitting network and bypass HTML construction; use fixture directly
        $backend->method('requestSearch')->willReturn([]);
        $backend->method('buildSearchHtml')->willReturn((string) ($fixture['html'] ?? ''));

        $query = (string) ($fixture['title'] ?? '');
        $page = $backend->search($query, 10);

        // Compare key fields with the Python dump
        $this->assertSame((string) ($fixture['url'] ?? ''), $page->url, 'url mismatch');
        $this->assertSame($query, $page->title, 'title mismatch');

        // Text can be long; assert exact match to ensure parity
        $this->assertSame((string) ($expected['text'] ?? ''), $page->text, 'text mismatch');

        // URLs mapping should match exactly (string keys 0..n)
        $this->assertSame((array) ($expected['urls'] ?? []), $page->urls, 'urls mapping mismatch');
    }

    /**
     * @param array<int, array<string|int, string>> $raw
     *
     * @return list<array{title:string,url:string,summary:string}>
     */
    private function normalizeItemsFixture(array $raw): array
    {
        $normalized = [];
        foreach ($raw as $item) {
            $normalized[] = [
                'title' => (string) ($item['title'] ?? ($item[0] ?? '')),
                'url' => (string) ($item['url'] ?? ($item[1] ?? '')),
                'summary' => (string) ($item['summary'] ?? ($item[2] ?? '')),
            ];
        }

        return $normalized;
    }

    private function getFixturesPath(): string
    {
        return __DIR__.'/../../dumps/SearxNG';
    }

    /**
     * @return array<string,mixed>
     */
    private function loadJson(string $filename): array
    {
        $path = $this->getFixturesPath().'/'.$filename;
        $data = file_get_contents($path);
        $this->assertNotFalse($data, 'Failed to read fixture '.$filename);

        $json = json_decode($data, true);
        $this->assertIsArray($json, 'Fixture is not valid JSON: '.$filename);

        return $json;
    }
}
