<?php

declare(strict_types=1);

namespace App\Config;

use Symfony\Component\PropertyAccess\Exception\ExceptionInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

final readonly class AppConfig
{
    private PropertyAccessorInterface $propertyAccessor;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private array $data,
    ) {
        $this->propertyAccessor = PropertyAccess::createPropertyAccessor();
    }

    public function getTransport(): string
    {
        $transport = strtolower((string) $this->getValue('general.transport', 'stdio'));

        return 'http' === $transport ? 'http' : 'stdio';
    }

    public function getPort(): int
    {
        $port = (int) $this->getValue('general.port', 8000);

        return $port > 0 ? $port : 8000;
    }

    public function getSearchViewTokens(): int
    {
        $value = (int) $this->getValue('display.search_view_tokens', 1024);

        return $value > 0 ? $value : 1024;
    }

    public function getSearchEncodingName(): string
    {
        $value = trim((string) $this->getValue('display.search_encoding_name', 'o200k_base'));

        return '' !== $value ? $value : 'o200k_base';
    }

    public function getOpenCacheTtlSeconds(): int
    {
        $value = (int) $this->getValue('general.open_cache_ttl_seconds', 300);

        return $value > 0 ? $value : 300;
    }

    public function getSearchCacheTtlSeconds(): int
    {
        $value = (int) $this->getValue('general.search_cache_ttl_seconds', 600);

        return $value > 0 ? $value : 600;
    }

    public function getSelectedSearcher(): string
    {
        $value = strtolower(trim((string) $this->getValue('searchers.selected', 'searxng')));

        return '' !== $value ? $value : 'searxng';
    }

    /**
     * @return array<string, mixed>
     */
    public function getSearcherConfig(string $name): array
    {
        $all = $this->getArray('searchers.providers');
        $config = $all[$name] ?? [];

        return \is_array($config) ? $config : [];
    }

    public function getSelectedReader(): string
    {
        return 'http';
    }

    /**
     * @return array<string, mixed>
     */
    public function getReaderConfig(string $name): array
    {
        $all = $this->getArray('readers.providers');
        $config = $all[$name] ?? [];

        return \is_array($config) ? $config : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function getArray(string $path): array
    {
        $value = $this->getValue($path, []);

        return \is_array($value) ? $value : [];
    }

    private function getValue(string $path, mixed $default): mixed
    {
        $segments = array_filter(explode('.', $path), static fn (string $segment): bool => '' !== $segment);
        $propertyPath = implode('', array_map(static fn (string $segment): string => '['.$segment.']', $segments));

        if ('' === $propertyPath) {
            return $default;
        }

        try {
            return $this->propertyAccessor->getValue($this->data, $propertyPath);
        } catch (ExceptionInterface) {
            return $default;
        }
    }
}
