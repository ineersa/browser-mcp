<?php

declare(strict_types=1);

namespace App\Tests\Service\Backend;

use App\Service\Backend\SearxNGBackend;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SearxNGBackendTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testRequestSearchBuildsItemsFromResultsFixture(): void
    {
        $fixtures = $this->loadJson('results.json');
        $expectedItems = $this->normalizeItemsFixture($this->loadJson('items.json')); // @phpstan-ignore-line

        // Create a partial mock overriding fetchSearxResults
        $client = $this->createStub(HttpClientInterface::class);
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

        $client = $this->createStub(HttpClientInterface::class);
        $backend = $this->getMockBuilder(SearxNGBackend::class)
            ->setConstructorArgs(['http://example.test', $client])
            ->onlyMethods(['requestSearch'])
            ->getMock();

        $backend->expects($this->once())
            ->method('requestSearch')
            ->with('SearxNG setup', 5)
            ->willReturn(\array_slice($items, 0, 5));

        $page = $backend->search('SearxNG setup', 5);

        $this->assertSame('', $page->url);
        $this->assertSame('SearxNG setup', $page->title);
        $this->assertSame((string) ($expected['text'] ?? ''), $page->text);
        $this->assertSame((array) ($expected['urls'] ?? []), $page->urls);
    }

    public function testFetchUsesGithubRawForBlobUrls(): void
    {
        $requested = [];
        $rawContent = "<?php\necho 'hello';\n";

        $httpClient = new MockHttpClient(static function (string $method, string $url) use (&$requested, $rawContent) {
            $requested[] = $method.' '.$url;
            if ('GET' !== $method || 'https://raw.githubusercontent.com/foo/bar/main/src/File.php' !== $url) {
                throw new \RuntimeException('Unexpected request: '.$method.' '.$url);
            }

            return new MockResponse($rawContent);
        });

        $backend = new SearxNGBackend('https://search.example', $httpClient);

        $page = $backend->fetch('https://github.com/foo/bar/blob/main/src/File.php');

        $this->assertSame('https://github.com/foo/bar/blob/main/src/File.php', $page->url);
        $this->assertSame('foo/bar/main/src/File.php', $page->title);
        $this->assertStringContainsString('<?php', $page->text);
        $this->assertStringContainsString("echo 'hello';", $page->text);
        $this->assertStringContainsString('```php', $page->text);
        $this->assertStringContainsString("\n```", $page->text);
        $this->assertStringContainsString('URL: https://github.com/foo/bar/blob/main/src/File.php', $page->text);
        $this->assertSame(['GET https://raw.githubusercontent.com/foo/bar/main/src/File.php'], $requested);
    }

    public function testFetchWrapsGithubRawHostContent(): void
    {
        $rawContent = "Line 1\nLine 2\n";
        $httpClient = new MockHttpClient(static function (string $method, string $url) use ($rawContent) {
            if ('GET' !== $method) {
                throw new \RuntimeException('Unexpected method: '.$method);
            }
            if ('https://raw.githubusercontent.com/foo/bar/main/README.md' !== $url) {
                throw new \RuntimeException('Unexpected URL: '.$url);
            }

            return new MockResponse($rawContent);
        });

        $backend = new SearxNGBackend('https://search.example', $httpClient);

        $page = $backend->fetch('https://raw.githubusercontent.com/foo/bar/main/README.md');

        $this->assertSame('https://raw.githubusercontent.com/foo/bar/main/README.md', $page->url);
        $this->assertSame('foo/bar/main/README.md', $page->title);
        $this->assertStringContainsString('Line 1', $page->text);
        $this->assertStringContainsString('Line 2', $page->text);
        $this->assertStringContainsString('URL: https://raw.githubusercontent.com/foo/bar/main/README.md', $page->text);
        $this->assertStringNotContainsString('```', $page->text);
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
