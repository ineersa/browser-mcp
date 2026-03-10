<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\ConfigLoader;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    public function testLoadResolvesEnvPlaceholdersRecursively(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir.'/config.yaml', <<<'YAML'
general:
  transport: "%env(TEST_TRANSPORT)%"
  port: "%env(TEST_MCP_PORT)%"
readers:
  providers:
    http:
      timeout_seconds: "%env(TEST_HTTP_TIMEOUT)%"
      user_agent: "%env(TEST_HTTP_UA)%"
YAML
);

        $snapshot = $this->snapshotEnv(['CONFIG_FILE', 'TEST_TRANSPORT', 'TEST_MCP_PORT', 'TEST_HTTP_TIMEOUT', 'TEST_HTTP_UA']);

        try {
            $_SERVER['CONFIG_FILE'] = 'config.yaml';
            $_ENV['TEST_TRANSPORT'] = 'http';
            $_ENV['TEST_MCP_PORT'] = '8080';
            $_ENV['TEST_HTTP_TIMEOUT'] = '12';
            $_SERVER['TEST_HTTP_UA'] = 'test-agent';

            $config = (new ConfigLoader($dir))->load();

            $this->assertSame('http', $config->getTransport());
            $this->assertSame(8080, $config->getPort());
            $this->assertSame('12', $config->getReaderConfig('http')['timeout_seconds']);
            $this->assertSame('test-agent', $config->getReaderConfig('http')['user_agent']);
        } finally {
            $this->restoreEnv($snapshot);
        }
    }

    public function testLoadThrowsWhenPlaceholderEnvVarIsMissing(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir.'/config.yaml', <<<'YAML'
general:
  port: "%env(MISSING_ENV_VAR)%"
YAML
);

        $snapshot = $this->snapshotEnv(['CONFIG_FILE', 'MISSING_ENV_VAR']);

        try {
            $_SERVER['CONFIG_FILE'] = 'config.yaml';
            unset($_ENV['MISSING_ENV_VAR'], $_SERVER['MISSING_ENV_VAR']);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Environment variable "MISSING_ENV_VAR" is not defined.');

            (new ConfigLoader($dir))->load();
        } finally {
            $this->restoreEnv($snapshot);
        }
    }

    private function makeTempDir(): string
    {
        $path = sys_get_temp_dir().'/browser-mcp-config-'.bin2hex(random_bytes(8));
        mkdir($path, 0777, true);

        return $path;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, array{env:mixed, server:mixed}>
     */
    private function snapshotEnv(array $keys): array
    {
        $snapshot = [];
        foreach ($keys as $key) {
            $snapshot[$key] = [
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
            ];
        }

        return $snapshot;
    }

    /**
     * @param array<string, array{env:mixed, server:mixed}> $snapshot
     */
    private function restoreEnv(array $snapshot): void
    {
        foreach ($snapshot as $key => $values) {
            if (null === $values['env']) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $values['env'];
            }

            if (null === $values['server']) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $values['server'];
            }
        }
    }
}
