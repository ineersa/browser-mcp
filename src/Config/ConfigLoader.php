<?php

declare(strict_types=1);

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

final readonly class ConfigLoader
{
    public function __construct(
        private string $projectDir,
    ) {
    }

    public function load(): AppConfig
    {
        $configFile = $_SERVER['CONFIG_FILE'] ?? $_ENV['CONFIG_FILE'] ?? getenv('CONFIG_FILE') ?: 'browser_config.yaml';
        $resolvedPath = $this->resolvePath((string) $configFile);

        if (!is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new \RuntimeException(sprintf(
                'Config file `%s` not found at `%s`.',
                (string) $configFile,
                $resolvedPath,
            ));
        }

        $parsed = Yaml::parseFile($resolvedPath);
        if (!is_array($parsed)) {
            throw new \RuntimeException(sprintf('Config file `%s` must contain a YAML object at root.', $resolvedPath));
        }

        return new AppConfig($parsed);
    }

    private function resolvePath(string $configFile): string
    {
        if ('' === trim($configFile)) {
            throw new \RuntimeException('CONFIG_FILE cannot be empty.');
        }

        if ($this->isAbsolutePath($configFile)) {
            return $configFile;
        }

        $baseDir = $this->projectDir;

        if (str_starts_with($this->projectDir, 'phar://')) {
            $argv0 = $_SERVER['argv'][0] ?? null;
            if (is_string($argv0) && '' !== $argv0) {
                $baseDir = dirname($argv0);
            } else {
                $cwd = getcwd();
                if (false !== $cwd) {
                    $baseDir = $cwd;
                }
            }
        }

        return rtrim($baseDir, '/\\').'/'.ltrim($configFile, '/\\');
    }

    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/')) {
            return true;
        }

        if (1 === preg_match('~^[A-Za-z]:[\\/]~', $path)) {
            return true;
        }

        return str_starts_with($path, 'phar://');
    }
}
