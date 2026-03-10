<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Config\AppConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ReaderFactory
{
    public function __construct(
        private AppConfig $config,
        private HttpClientInterface $httpClient,
        private string $projectDir,
    ) {
    }

    public function create(): ReaderInterface
    {
        $reader = $this->config->getSelectedReader();
        $readerConfig = $this->config->getReaderConfig($reader);
        $httpConfig = $this->config->getReaderConfig('http');

        $httpTimeoutSeconds = (float) ($httpConfig['timeout_seconds'] ?? 30.0);
        if ($httpTimeoutSeconds <= 0) {
            $httpTimeoutSeconds = 30.0;
        }
        $httpMaxRetries = max(0, (int) ($httpConfig['max_retries'] ?? 2));

        return match ($reader) {
            'http' => new HttpReader(
                client: $this->httpClient,
                timeoutSeconds: $httpTimeoutSeconds,
                maxRetries: $httpMaxRetries,
            ),
            'puppeteer' => new PuppeteerReader(
                client: $this->httpClient,
                scriptPath: $this->resolvePath((string) ($readerConfig['script_path'] ?? 'bin/puppeteer-fetch.js')),
                nodeBinary: (string) ($readerConfig['node_binary'] ?? 'node'),
                timeoutSeconds: max(1, (int) ($readerConfig['timeout_seconds'] ?? 45)),
                httpTimeoutSeconds: $httpTimeoutSeconds,
                httpMaxRetries: $httpMaxRetries,
            ),
            default => throw new \UnhandledMatchError('Unknown reader provider'),
        };
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/') || str_starts_with($path, 'phar://')) {
            return $path;
        }

        return rtrim($this->projectDir, '/').'/'.ltrim($path, '/');
    }
}
