<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Domain\Read\ReadRequest;
use App\Service\Reader\HttpReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpReaderTest extends TestCase
{
    public function testReadMapsDocumentFromUrl(): void
    {
        $url = 'https://example.com/article';
        $html = '<html><head><title>Article</title></head><body><p>Hello</p></body></html>';
        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse($html));

        $reader = new HttpReader($httpClient);
        $result = $reader->read(new ReadRequest(url: $url, canonicalUrl: $url));

        $this->assertSame($url, $result->url);
        $this->assertSame($url, $result->canonicalUrl);
        $this->assertSame('searxng', $result->provider);
        $this->assertSame($url, $result->title);
        $this->assertStringContainsString('Hello', $result->markdown);
    }

    public function testReadUsesGithubRawForBlobUrls(): void
    {
        $requested = [];
        $rawContent = "<?php\necho 'hello';\n";

        $httpClient = new MockHttpClient(static function (string $method, string $url) use (&$requested, $rawContent): MockResponse {
            $requested[] = $method.' '.$url;
            if ('GET' !== $method || 'https://raw.githubusercontent.com/foo/bar/main/src/File.php' !== $url) {
                throw new \RuntimeException('Unexpected request: '.$method.' '.$url);
            }

            return new MockResponse($rawContent);
        });

        $reader = new HttpReader($httpClient);
        $page = $reader->read(new ReadRequest(
            url: 'https://github.com/foo/bar/blob/main/src/File.php',
            canonicalUrl: 'https://github.com/foo/bar/blob/main/src/File.php',
        ))->toPageContents();

        $this->assertSame('https://github.com/foo/bar/blob/main/src/File.php', $page->url);
        $this->assertSame('foo/bar/main/src/File.php', $page->title);
        $this->assertStringContainsString('<?php', $page->text);
        $this->assertStringContainsString("echo 'hello';", $page->text);
        $this->assertStringContainsString('```php', $page->text);
        $this->assertStringContainsString("\n```", $page->text);
        $this->assertStringContainsString('URL: https://github.com/foo/bar/blob/main/src/File.php', $page->text);
        $this->assertSame(['GET https://raw.githubusercontent.com/foo/bar/main/src/File.php'], $requested);
    }

    public function testReadWrapsGithubRawHostContent(): void
    {
        $rawContent = "Line 1\nLine 2\n";
        $httpClient = new MockHttpClient(static function (string $method, string $url) use ($rawContent): MockResponse {
            if ('GET' !== $method) {
                throw new \RuntimeException('Unexpected method: '.$method);
            }
            if ('https://raw.githubusercontent.com/foo/bar/main/README.md' !== $url) {
                throw new \RuntimeException('Unexpected URL: '.$url);
            }

            return new MockResponse($rawContent);
        });

        $reader = new HttpReader($httpClient);
        $page = $reader->read(new ReadRequest(
            url: 'https://raw.githubusercontent.com/foo/bar/main/README.md',
            canonicalUrl: 'https://raw.githubusercontent.com/foo/bar/main/README.md',
        ))->toPageContents();

        $this->assertSame('https://raw.githubusercontent.com/foo/bar/main/README.md', $page->url);
        $this->assertSame('foo/bar/main/README.md', $page->title);
        $this->assertStringContainsString('Line 1', $page->text);
        $this->assertStringContainsString('Line 2', $page->text);
        $this->assertStringContainsString('URL: https://raw.githubusercontent.com/foo/bar/main/README.md', $page->text);
        $this->assertStringNotContainsString('```', $page->text);
    }
}
