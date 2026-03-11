<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Domain\Read\ReadRequest;
use App\Service\Reader\JinaReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class JinaReaderTest extends TestCase
{
    public function testReadFetchesMarkdownWithoutAuthorizationWhenTokenMissing(): void
    {
        $url = 'https://example.com/article';
        $optionsSeen = [];

        $httpClient = new MockHttpClient(static function (string $method, string $requestUrl, array $options) use (&$optionsSeen, $url): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://r.jina.ai/'.$url, $requestUrl);
            $optionsSeen = $options;

            return new MockResponse("# Example title\n\nHello from Jina reader.");
        });

        $reader = new JinaReader($httpClient, '', 15.0, 1);
        $result = $reader->read(new ReadRequest(url: $url, canonicalUrl: $url));

        $this->assertSame('jinaai', $result->provider);
        $this->assertSame('Example title', $result->title);
        $this->assertStringContainsString('Hello from Jina reader.', $result->markdown);
        $this->assertSame(15.0, $optionsSeen['timeout'] ?? null);
        $this->assertSame(1, $optionsSeen['max_retries'] ?? null);
        $this->assertArrayNotHasKey('authorization', $optionsSeen['normalized_headers'] ?? []);
    }

    public function testReadAddsAuthorizationHeaderWhenTokenConfigured(): void
    {
        $url = 'https://example.com/article';

        $httpClient = new MockHttpClient(static function (string $method, string $requestUrl, array $options) use ($url): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://r.jina.ai/'.$url, $requestUrl);
            self::assertContains('Authorization: Bearer test-reader-token', $options['normalized_headers']['authorization'] ?? []);

            return new MockResponse("Plain response body\nnext line");
        });

        $reader = new JinaReader($httpClient, 'test-reader-token', 20.0, 2);
        $result = $reader->read(new ReadRequest(url: $url, canonicalUrl: $url));

        $this->assertSame('Plain response body', $result->title);
        $this->assertStringContainsString('next line', $result->markdown);
    }
}
