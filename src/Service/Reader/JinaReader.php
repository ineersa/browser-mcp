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

final class JinaReader implements ReaderContract
{
    private const BASE_URL = 'https://r.jina.ai';

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $token = '',
        private readonly float $timeoutSeconds = 15.0,
        private readonly int $maxRetries = 1,
    ) {
    }

    public function getProvider(): string
    {
        return 'jinaai';
    }

    public function read(ReadRequest $request): ReadDocument
    {
        $fetchUrl = '' !== $request->canonicalUrl ? $request->canonicalUrl : $request->url;
        $markdown = $this->fetchMarkdown($fetchUrl);

        return new ReadDocument(
            url: $request->url,
            canonicalUrl: $fetchUrl,
            title: $this->extractTitle($markdown) ?? $fetchUrl,
            markdown: $markdown,
            references: [],
            provider: $this->getProvider(),
        );
    }

    /**
     * @throws BackendError
     */
    private function fetchMarkdown(string $url): string
    {
        $headers = [
            'Accept' => 'text/markdown,text/plain;q=0.9,*/*;q=0.8',
        ];

        $token = trim($this->token);
        if ('' !== $token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $readerUrl = self::BASE_URL.'/'.ltrim($url, '/');

        try {
            $response = $this->client->request('GET', $readerUrl, [
                'timeout' => $this->timeoutSeconds > 0 ? $this->timeoutSeconds : 15.0,
                'max_retries' => max(0, $this->maxRetries),
                'headers' => $headers,
            ]);

            return Utilities::ensureUtf8($response->getContent());
        } catch (ClientExceptionInterface|ServerExceptionInterface|RedirectionExceptionInterface|TransportExceptionInterface $e) {
            throw new BackendError(sprintf('HTTP error for %s: %s', $readerUrl, Utilities::maybeTruncate($e->getMessage(), 500)), previous: $e)->setHint('This may be a network timeout, server error, or the URL may be inaccessible. Try retrying the request or check if the URL is valid and the server is responding.');
        }
    }

    private function extractTitle(string $markdown): ?string
    {
        $lines = preg_split('/\R/u', $markdown);
        if (false === $lines) {
            return null;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed) {
                continue;
            }

            if (1 === preg_match('/^#{1,6}\s+(.*)$/u', $trimmed, $matches)) {
                $heading = trim($matches[1]);

                return '' !== $heading ? $heading : null;
            }

            return mb_substr($trimmed, 0, 120);
        }

        return null;
    }
}
