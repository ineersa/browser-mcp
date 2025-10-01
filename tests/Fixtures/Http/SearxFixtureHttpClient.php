<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Http;

use JsonException;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SearxFixtureHttpClient extends MockHttpClient
{
    public function __construct()
    {
        $results = $this->loadJson('results.json');

        parent::__construct(function (string $method, string $url) use ($results): MockResponse {
            if ('GET' !== $method) {
                throw new RuntimeException(\sprintf('Unexpected HTTP method: %s %s', $method, $url));
            }

            if (str_contains($url, '/search')) {
                $body = json_encode(['results' => $results], \JSON_THROW_ON_ERROR);

                return new MockResponse($body, ['http_code' => 200]);
            }

            throw new RuntimeException(\sprintf('Unexpected request URL: %s', $url));
        });
    }

    /**
     * @return array<mixed>
     */
    private function loadJson(string $filename): array
    {
        $path = __DIR__.'/../../dumps/SearxNG/'.$filename;
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new RuntimeException(\sprintf('Unable to read fixture file: %s', $path));
        }

        try {
            $json = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(\sprintf('Invalid JSON in fixture %s: %s', $filename, $exception->getMessage()), 0, $exception);
        }

        if (!\is_array($json)) {
            throw new RuntimeException(\sprintf('Fixture %s does not decode to an array.', $filename));
        }

        return $json;
    }
}
