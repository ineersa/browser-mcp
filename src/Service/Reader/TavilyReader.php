<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Domain\Read\ReadDocument;
use App\Domain\Read\ReadRequest;
use App\Service\Contracts\ReaderContract;
use App\Service\Exception\BackendError;
use App\Service\Utilities;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TavilyReader implements ReaderContract
{
    private const API_URL = 'https://api.tavily.com/extract';

    public function __construct(
        private readonly string $token,
        private readonly HttpClientInterface $client,
        private readonly float $timeoutSeconds = 15.0,
        private readonly int $maxRetries = 1,
    ) {
    }

    public function getProvider(): string
    {
        return 'tavily';
    }

    public function read(ReadRequest $request): ReadDocument
    {
        $fetchUrl = '' !== $request->canonicalUrl ? $request->canonicalUrl : $request->url;
        $payload = $this->requestExtract($fetchUrl);

        return new ReadDocument(
            url: $request->url,
            canonicalUrl: $fetchUrl,
            title: $payload['title'] ?? $fetchUrl,
            markdown: $payload['markdown'],
            references: [],
            provider: $this->getProvider(),
        );
    }

    /**
     * @return array{title?:string,markdown:string}
     */
    private function requestExtract(string $url): array
    {
        if ('' === trim($this->token)) {
            throw new BackendError('Tavily reader token is not configured. Set readers.providers.tavily.token in browser_config.yaml.');
        }

        try {
            $response = $this->client->request('POST', self::API_URL, [
                'timeout' => $this->timeoutSeconds > 0 ? $this->timeoutSeconds : 15.0,
                'max_retries' => max(0, $this->maxRetries),
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$this->token,
                ],
                'json' => [
                    'urls' => [$url],
                ],
            ]);
            $content = $response->getContent();
        } catch (ClientExceptionInterface|ServerExceptionInterface|RedirectionExceptionInterface|TransportExceptionInterface $e) {
            throw new BackendError(sprintf('HTTP error for %s: %s', self::API_URL, Utilities::maybeTruncate($e->getMessage(), 500)), previous: $e)->setHint('This may be a network timeout, server error, or the URL may be inaccessible. Try retrying the request or check if the URL is valid and the server is responding.');
        }

        $json = json_decode($content, true);
        if (!is_array($json)) {
            if (\JSON_ERROR_NONE !== json_last_error()) {
                throw new BackendError(sprintf('JSON error: %s.', json_last_error_msg()));
            }

            throw new BackendError('Tavily extract response is not JSON');
        }

        $results = $json['results'] ?? [];
        if (!is_array($results) || !isset($results[0]) || !is_array($results[0])) {
            throw new BackendError('Tavily extract response does not contain results');
        }

        $first = $results[0];
        $markdown = trim((string) ($first['raw_content'] ?? $first['content'] ?? ''));
        if ('' === $markdown) {
            throw new BackendError('Tavily extract returned empty content');
        }

        $title = trim((string) ($first['title'] ?? ''));
        $out = [
            'markdown' => $markdown,
        ];
        if ('' !== $title) {
            $out['title'] = $title;
        }

        return $out;
    }
}
