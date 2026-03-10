<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Exception\BackendError;
use App\Service\Utilities;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PuppeteerReader extends HttpReader
{
    public function __construct(
        HttpClientInterface $client,
        private readonly string $scriptPath,
        private readonly string $nodeBinary,
        private readonly int $timeoutSeconds,
        float $httpTimeoutSeconds = 30.0,
        int $httpMaxRetries = 2,
    ) {
        parent::__construct($client, $httpTimeoutSeconds, $httpMaxRetries);
    }

    protected function fetchHtml(string $url): string
    {
        try {
            return $this->fetchWithPuppeteer($url);
        } catch (BackendError) {
            return $this->httpGet($url);
        }
    }

    /**
     * @throws BackendError
     */
    private function fetchWithPuppeteer(string $url): string
    {
        if (!is_file($this->scriptPath) || !is_readable($this->scriptPath)) {
            throw new BackendError(sprintf('Puppeteer script not found at %s', $this->scriptPath))->setHint('Ensure the puppeteer fetch script exists and is readable. Reinstall dependencies or check the project files.');
        }

        $process = new Process([$this->nodeBinary, $this->resolveScriptPath(), $url]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ExceptionInterface $e) {
            throw new BackendError(sprintf('Failed to start Puppeteer for %s: %s', $url, Utilities::maybeTruncate($e->getMessage(), 300)), previous: $e)->setHint('Verify that Node.js is installed, the configured node binary is correct, and puppeteer dependencies are available.');
        }

        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput());
            if ('' === $error) {
                $error = trim($process->getOutput());
            }

            $message = sprintf('Puppeteer exited with status %d for %s: %s', $process->getExitCode() ?? -1, $url, Utilities::maybeTruncate($error, 500));

            throw new BackendError($message)->setHint('Check the Puppeteer script output above. Installing dependencies with npm and ensuring the target page is accessible may resolve the issue.');
        }

        $html = $process->getOutput();
        if ('' === $html) {
            throw new BackendError(sprintf('Puppeteer returned empty HTML for %s', $url))->setHint('The page may not have fully loaded or blocked automation. Falling back to direct HTTP request.');
        }

        return $html;
    }

    /**
     * @throws BackendError
     */
    private function resolveScriptPath(): string
    {
        if (!$this->isPharPath($this->scriptPath)) {
            return $this->scriptPath;
        }

        $scriptName = basename($this->scriptPath);
        $scriptDir = dirname($this->scriptPath);

        $finder = Finder::create()->files()->name($scriptName);
        try {
            $finder->in($scriptDir);
        } catch (\InvalidArgumentException) {
            return $this->extractScriptWithStream($scriptName);
        }

        foreach ($finder as $file) {
            return $this->dumpScriptToTemp($file->getContents(), $scriptName);
        }

        return $this->extractScriptWithStream($scriptName);
    }

    /**
     * @throws BackendError
     */
    private function extractScriptWithStream(string $scriptName): string
    {
        $contents = @file_get_contents($this->scriptPath);
        if (false === $contents) {
            throw new BackendError(sprintf('Unable to read puppeteer script at %s', $this->scriptPath))->setHint('Verify the PHAR archive contains bin/puppeteer-fetch.js and is readable.');
        }

        return $this->dumpScriptToTemp($contents, $scriptName);
    }

    private function isPharPath(string $path): bool
    {
        return str_starts_with($path, 'phar://') || str_starts_with($path, 'phar:');
    }

    /**
     * @throws BackendError
     */
    private function dumpScriptToTemp(string $contents, string $scriptName): string
    {
        $tmpFile = rtrim(sys_get_temp_dir(), '/\\').'/browser-mcp-'.sha1($this->scriptPath).'-'.$scriptName;

        $filesystem = new Filesystem();
        try {
            $filesystem->dumpFile($tmpFile, $contents);
        } catch (IOExceptionInterface $e) {
            throw (new BackendError(sprintf('Unable to write puppeteer script to %s', $tmpFile), previous: $e))->setHint('Check system tmp directory permissions and available space.');
        }

        return $tmpFile;
    }
}
