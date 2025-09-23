<?php

declare(strict_types=1);

namespace App\Service\Backend;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class BackendFactory
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function create(string $driver, string $backendUrl): BackendInterface
    {
        return match ($driver) {
            'searxng' => new SearxNGBackend($backendUrl, $this->httpClient),
            default => throw new \UnhandledMatchError('Unknown backend'),
        };
    }
}
