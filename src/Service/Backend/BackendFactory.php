<?php

declare(strict_types=1);

namespace App\Service\Backend;

use App\Service\PuppeteerWorker;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class BackendFactory
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?PuppeteerWorker $puppeteerWorker = null,
        private readonly bool $usePuppeteer = false,
    ) {
    }

    public function create(string $driver, string $backendUrl): BackendInterface
    {
        return match ($driver) {
            'searxng' => new SearxNGBackend(
                $backendUrl,
                $this->httpClient,
                $this->puppeteerWorker,
                $this->usePuppeteer,
            ),
            default => throw new \UnhandledMatchError('Unknown backend'),
        };
    }
}
