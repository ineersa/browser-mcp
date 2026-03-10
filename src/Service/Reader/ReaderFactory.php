<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Config\AppConfig;
use App\Service\Contracts\ReaderContract;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ReaderFactory
{
    public function __construct(
        private AppConfig $config,
        private HttpClientInterface $httpClient,
    ) {
    }

    public function create(): ReaderContract
    {
        $reader = $this->config->getSelectedReader();
        $httpConfig = $this->config->getReaderConfig('http');

        $httpTimeoutSeconds = (float) ($httpConfig['timeout_seconds'] ?? 30.0);
        if ($httpTimeoutSeconds <= 0) {
            $httpTimeoutSeconds = 30.0;
        }
        $httpMaxRetries = max(0, (int) ($httpConfig['max_retries'] ?? 2));
        $httpUserAgent = trim((string) ($httpConfig['user_agent'] ?? HttpReader::DEFAULT_USER_AGENT));
        if ('' === $httpUserAgent) {
            $httpUserAgent = HttpReader::DEFAULT_USER_AGENT;
        }
        $httpNoiseClassTokens = array_values(array_filter(
            is_array($httpConfig['noise_class_tokens'] ?? null) ? $httpConfig['noise_class_tokens'] : [],
            static fn (mixed $value): bool => is_string($value) && '' !== trim($value),
        ));
        if ('http' !== $reader) {
            throw new \UnhandledMatchError('Unknown reader provider');
        }

        return new HttpReader(
                client: $this->httpClient,
                timeoutSeconds: $httpTimeoutSeconds,
                maxRetries: $httpMaxRetries,
                userAgent: $httpUserAgent,
                noiseClassTokens: $httpNoiseClassTokens,
            );
    }
}
