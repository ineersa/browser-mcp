<?php

declare(strict_types=1);

namespace App\Tests\Service\Searcher;

use App\Domain\Search\SearchRequest;
use App\Service\Exception\BackendError;
use App\Service\Searcher\DuckDuckGoLiteSearcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DuckDuckGoLiteSearcherTest extends TestCase
{
    public function testSearchParsesDuckDuckGoLiteResults(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
<body>
    <table></table>
    <table>
        <tr>
            <td><a class="result-link" href="/l/?uddg=https%3A%2F%2Fexample.com%2Falpha">Alpha Result</a></td>
        </tr>
        <tr>
            <td class="result-snippet">Alpha summary</td>
        </tr>
        <tr>
            <td><a class="result-link" href="https://example.org/beta">Beta Result</a></td>
        </tr>
        <tr>
            <td class="result-snippet">Beta summary</td>
        </tr>
    </table>
</body>
</html>
HTML;

        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($html): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringStartsWith('https://lite.duckduckgo.com/lite/?q=alpha', $url);
            self::assertSame(5.0, $options['timeout'] ?? null);
            self::assertContains('User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:148.0) Gecko/20100101 Firefox/148.0', $options['normalized_headers']['user-agent'] ?? []);

            return new MockResponse($html);
        });

        $searcher = new DuckDuckGoLiteSearcher(5, 1, $client);
        $result = $searcher->search(new SearchRequest(query: 'alpha', limit: 2));

        $this->assertSame('duckduckgo', $result->provider);
        $this->assertCount(2, $result->hits);
        $this->assertSame('https://example.com/alpha', $result->hits[0]->url);
        $this->assertSame('Alpha summary', $result->hits[0]->snippet);
    }

    public function testSearchRetriesAndFailsAfterMaxRetries(): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('oops', ['http_code' => 500]);
        });

        $searcher = new DuckDuckGoLiteSearcher(5, 1, $client);

        $this->expectException(BackendError::class);
        $this->expectExceptionMessage('HTTP error for https://lite.duckduckgo.com/lite/');

        try {
            $searcher->search(new SearchRequest(query: 'alpha', limit: 2));
        } finally {
            $this->assertSame(2, $calls);
        }
    }

    public function testSearchFailsOnDuckDuckGoAnomalyPage(): void
    {
        $html = <<<'HTML'
<html>
<body>
  <form id="img-form" action="//duckduckgo.com/anomaly.js"></form>
</body>
</html>
HTML;

        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse($html));
        $searcher = new DuckDuckGoLiteSearcher(5, 1, $client);

        $this->expectException(BackendError::class);
        $this->expectExceptionMessage('anti-bot challenge page');

        $searcher->search(new SearchRequest(query: 'alpha', limit: 2));
    }
}
