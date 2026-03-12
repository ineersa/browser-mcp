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
        return match ($reader) {
            'http' => $this->createHttpReader(),
            'jina', 'jinaai' => $this->createJinaReader(),
            'tavily' => $this->createTavilyReader(),
            default => throw new \UnhandledMatchError('Unknown reader provider'),
        };
    }

    private function createHttpReader(): HttpReader
    {
        $config = $this->config->getReaderConfig('http');

        $timeoutSeconds = (float) ($config['timeout_seconds'] ?? 30.0);
        if ($timeoutSeconds <= 0) {
            $timeoutSeconds = 30.0;
        }

        $userAgent = trim((string) ($config['user_agent'] ?? HttpReader::DEFAULT_USER_AGENT));
        if ('' === $userAgent) {
            $userAgent = HttpReader::DEFAULT_USER_AGENT;
        }

        $noiseClassTokens = array_values(array_filter(
            is_array($config['noise_class_tokens'] ?? null) ? $config['noise_class_tokens'] : [],
            static fn (mixed $value): bool => is_string($value) && '' !== trim($value),
        ));

        return new HttpReader(
                client: $this->httpClient,
                timeoutSeconds: $timeoutSeconds,
                maxRetries: max(0, (int) ($config['max_retries'] ?? 2)),
                userAgent: $userAgent,
                noiseClassTokens: $noiseClassTokens,
            );
    }

    private function createJinaReader(): JinaReader
    {
        $config = $this->config->getReaderConfig('jinaai');
        if ([] === $config) {
            $config = $this->config->getReaderConfig('jina');
        }

        return new JinaReader(
            client: $this->httpClient,
            token: trim((string) ($config['token'] ?? '')),
            timeoutSeconds: (float) ($config['timeout_seconds'] ?? 15.0),
            maxRetries: max(0, (int) ($config['max_retries'] ?? 1)),
        );
    }

    private function createTavilyReader(): TavilyReader
    {
        $config = $this->config->getReaderConfig('tavily');

        return new TavilyReader(
            token: trim((string) ($config['token'] ?? '')),
            client: $this->httpClient,
            timeoutSeconds: (float) ($config['timeout_seconds'] ?? 15.0),
            maxRetries: max(0, (int) ($config['max_retries'] ?? 1)),
        );
    }
}
