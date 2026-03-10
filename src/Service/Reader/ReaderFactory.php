<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ReaderFactory
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private bool $usePuppeteer = false,
        private string $puppeteerScriptPath = '',
        private string $puppeteerNodeBinary = 'node',
        private int $puppeteerTimeoutSeconds = 45,
    ) {
    }

    public function create(string $provider): ReaderInterface
    {
        return match ($provider) {
            'searxng', 'searx' => $this->usePuppeteer
                ? new PuppeteerReader(
                    client: $this->httpClient,
                    scriptPath: $this->puppeteerScriptPath,
                    nodeBinary: $this->puppeteerNodeBinary,
                    timeoutSeconds: $this->puppeteerTimeoutSeconds,
                )
                : new HttpReader(client: $this->httpClient),
            default => throw new \UnhandledMatchError('Unknown reader provider'),
        };
    }
}
