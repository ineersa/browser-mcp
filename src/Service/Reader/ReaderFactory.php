<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\PuppeteerWorker;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ReaderFactory
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ?PuppeteerWorker $puppeteerWorker = null,
        private bool $usePuppeteer = false,
    ) {
    }

    public function create(string $provider): ReaderInterface
    {
        return match ($provider) {
            'searxng', 'searx' => new SearxNGReader(
                client: $this->httpClient,
                puppeteerWorker: $this->puppeteerWorker,
                usePuppeteer: $this->usePuppeteer,
            ),
            default => throw new \UnhandledMatchError('Unknown reader provider'),
        };
    }
}
