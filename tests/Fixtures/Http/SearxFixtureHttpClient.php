<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Http;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SearxFixtureHttpClient extends MockHttpClient
{
    public function __construct()
    {
        $results = $this->loadJson('results.json');
        $testOpenPageResults = $this->loadJson('test_open_page_results.json');
        $openPageHtml = $this->loadFile('open_page.html');
        $openPageFragment = 'cbracco/html5-test-page/refs/heads/master/index.html';

        parent::__construct(function (string $method, string $url) use ($results, $testOpenPageResults, $openPageHtml, $openPageFragment): MockResponse {
            if ('GET' !== $method) {
                throw new \RuntimeException(\sprintf('Unexpected HTTP method: %s %s', $method, $url));
            }

            if (str_contains($url, '/search')) {
                // Check if this is the "Test open page" query
                if (str_contains($url, 'Test+open+page') || str_contains($url, 'Test%20open%20page')) {
                    $body = json_encode(['results' => $testOpenPageResults], \JSON_THROW_ON_ERROR);
                } else {
                    $body = json_encode(['results' => $results], \JSON_THROW_ON_ERROR);
                }

                return new MockResponse($body, ['http_code' => 200]);
            }

            if (str_contains($url, $openPageFragment)) {
                return new MockResponse($openPageHtml, ['http_code' => 200]);
            }

            throw new \RuntimeException(\sprintf('Unexpected request URL: %s', $url));
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
            throw new \RuntimeException(\sprintf('Unable to read fixture file: %s', $path));
        }

        try {
            $json = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(\sprintf('Invalid JSON in fixture %s: %s', $filename, $exception->getMessage()), 0, $exception);
        }

        if (!\is_array($json)) {
            throw new \RuntimeException(\sprintf('Fixture %s does not decode to an array.', $filename));
        }

        return $json;
    }

    private function loadFile(string $filename): string
    {
        $path = __DIR__.'/../../dumps/SearxNG/'.$filename;
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException(\sprintf('Unable to read fixture file: %s', $path));
        }

        return $contents;
    }
}
