<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Config\AppConfig;
use App\Domain\Read\ReadRequest;
use App\Service\Reader\ReaderFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ReaderFactoryTest extends TestCase
{
    public function testCreateBuildsJinaReaderFromConfig(): void
    {
        $config = new AppConfig([
            'readers' => [
                'selected' => 'jinaai',
                'providers' => [
                    'jinaai' => [
                        'token' => 'resolved-reader-token',
                        'timeout_seconds' => 15,
                        'max_retries' => 2,
                    ],
                ],
            ],
        ]);

        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://r.jina.ai/https://example.com/page', $url);
            self::assertContains('Authorization: Bearer resolved-reader-token', $options['normalized_headers']['authorization'] ?? []);

            return new MockResponse("# Page\n\nContent");
        });

        $reader = (new ReaderFactory($config, $client))->create();
        $document = $reader->read(new ReadRequest(url: 'https://example.com/page', canonicalUrl: 'https://example.com/page'));

        $this->assertSame('jinaai', $document->provider);
        $this->assertSame('Page', $document->title);
    }
}
