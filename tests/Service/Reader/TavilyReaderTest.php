<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Domain\Read\ReadRequest;
use App\Service\Exception\BackendError;
use App\Service\Reader\TavilyReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TavilyReaderTest extends TestCase
{
    public function testReadUsesExtractEndpointAndMapsResult(): void
    {
        $url = 'https://www.britannica.com/science/general-relativity';
        $optionsSeen = [];

        $client = new MockHttpClient(static function (string $method, string $requestUrl, array $options) use (&$optionsSeen): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.tavily.com/extract', $requestUrl);
            $optionsSeen = $options;

            return new MockResponse('{"results":[{"title":"General relativity","raw_content":"# General relativity\n\nA theory of gravitation."}]}');
        });

        $reader = new TavilyReader('test-reader-token', $client, 15.0, 1);
        $result = $reader->read(new ReadRequest(url: $url, canonicalUrl: $url));

        $this->assertSame('tavily', $result->provider);
        $this->assertSame('General relativity', $result->title);
        $this->assertStringContainsString('A theory of gravitation.', $result->markdown);
        $this->assertSame(15.0, $optionsSeen['timeout'] ?? null);
        $this->assertSame(1, $optionsSeen['max_retries'] ?? null);
        $this->assertContains('Authorization: Bearer test-reader-token', $optionsSeen['normalized_headers']['authorization'] ?? []);
        $body = json_decode((string) ($optionsSeen['body'] ?? ''), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame([$url], ($body['urls'] ?? []));
    }

    public function testReadThrowsWhenTokenMissing(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{}'));
        $reader = new TavilyReader('', $client);

        $this->expectException(BackendError::class);
        $this->expectExceptionMessage('Tavily reader token is not configured.');

        $reader->read(new ReadRequest(url: 'https://example.com', canonicalUrl: 'https://example.com'));
    }
}
