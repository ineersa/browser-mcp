<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Exception\BackendError;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

final readonly class PuppeteerWorker
{
    public function __construct(
        private string $scriptPath,
        private string $nodeBinary,
        private int $timeoutSeconds,
    ) {
    }

    /**
     * @throws BackendError
     */
    public function fetch(string $url): string
    {
        return $this->fetchWithPuppeteer($url);
    }

    /**
     * @throws BackendError
     */
    private function fetchWithPuppeteer(string $url): string
    {
        if (!is_file($this->scriptPath) || !is_readable($this->scriptPath)) {
            throw new BackendError(\sprintf('Puppeteer script not found at %s', $this->scriptPath))->setHint('Ensure the puppeteer fetch script exists and is readable. Reinstall dependencies or check the project files.');
        }

        $process = new Process([$this->nodeBinary, $this->scriptPath, $url]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ExceptionInterface $e) {
            throw new BackendError(\sprintf('Failed to start Puppeteer for %s: %s', $url, Utilities::maybeTruncate($e->getMessage(), 300)), previous: $e)->setHint('Verify that Node.js is installed, the configured node binary is correct, and puppeteer dependencies are available.');
        }

        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput());
            if ('' === $error) {
                $error = trim($process->getOutput());
            }

            $message = \sprintf('Puppeteer exited with status %d for %s: %s', $process->getExitCode() ?? -1, $url, Utilities::maybeTruncate($error, 500));

            throw new BackendError($message)->setHint('Check the Puppeteer script output above. Installing dependencies with npm and ensuring the target page is accessible may resolve the issue.');
        }

        $html = $process->getOutput();
        if ('' === $html) {
            throw new BackendError(\sprintf('Puppeteer returned empty HTML for %s', $url))->setHint('The page may not have fully loaded or blocked automation. Adjust the script or disable Puppeteer to fall back to HTTP.');
        }

        return $html;
    }
}
