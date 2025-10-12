<?php

declare(strict_types=1);

namespace App\Tests\Service\Backend;

use App\Service\Backend\SearxNGBackend;
use PHPUnit\Framework\TestCase;
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

    public function testSearchFormatsResultsWithCanonicalUrls(): void
    {
        $items = $this->normalizeItemsFixture($this->loadJson('items.json')); // @phpstan-ignore-line
        $expected = $this->loadJson('search_page_contents.json');

        $client = $this->createMock(HttpClientInterface::class);
        $backend = $this->getMockBuilder(SearxNGBackend::class)
            ->setConstructorArgs(['http://example.test', $client])
            ->onlyMethods(['requestSearch'])
            ->getMock();

        $backend->expects($this->once())
            ->method('requestSearch')
            ->with('SearxNG setup', 5)
            ->willReturn(array_slice($items, 0, 5));

        $page = $backend->search('SearxNG setup', 5);

        $this->assertSame('', $page->url);
        $this->assertSame('SearxNG setup', $page->title);
        $this->assertSame((string) ($expected['text'] ?? ''), $page->text);
        $this->assertSame((array) ($expected['urls'] ?? []), $page->urls);
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
