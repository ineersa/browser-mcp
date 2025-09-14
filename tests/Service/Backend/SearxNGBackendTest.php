<?php

declare(strict_types=1);

namespace App\Tests\Service\Backend;

use App\Service\Backend\SearxNGBackend;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SearxNGBackendTest extends TestCase
{
    public function testRequestSearchBuildsItemsFromResultsFixture(): void
    {
        $fixtures = $this->loadJson('results.json');
        $expectedItems = $this->loadJson('items.json');

        // Create a partial mock overriding fetchSearxResults
        $client = $this->createMock(HttpClientInterface::class);
        $backend = $this->getMockBuilder(SearxNGBackend::class)
            ->setConstructorArgs(['http://example.test', 'web', $client])
            ->onlyMethods(['fetchSearxResults'])
            ->getMock();

        $backend->method('fetchSearxResults')
            ->willReturn($fixtures);

        $items = $backend->requestSearch('query', 10);

        $this->assertSame($expectedItems, $items);
    }

    public function testBuildSearchHtmlMatchesFixture(): void
    {
        $items = $this->loadJson('items.json');
        $expectedHtml = file_get_contents($this->getFixturesPath().'/html.html');
        $this->assertNotFalse($expectedHtml, 'Failed to read html.html');

        $backend = new SearxNGBackend('http://example.test', 'web', HttpClient::create());
        $html = $backend->buildSearchHtml($items);

        $this->assertSame($expectedHtml, $html);
    }

    private function getFixturesPath(): string
    {
        return __DIR__.'/../../dumps/SearxNG';
    }

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
