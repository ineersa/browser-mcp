<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Exception\BackendError;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class PuppeteerWorker
{
    public function __construct(
        private string $scriptPath,
        private string $nodeBinary,
        private int $timeoutSeconds,
        private ?HttpClientInterface $httpClient = null,
    ) {
    }

    /**
     * @throws BackendError
     */
    public function fetch(string $url): string
    {
        // Try to convert GitHub URLs to raw URLs and fetch via HTTP
        $rawUrl = $this->convertGitHubUrl($url);

        if (null !== $rawUrl && null !== $this->httpClient) {
            try {
                $response = $this->httpClient->request('GET', $rawUrl, [
                    'timeout' => $this->timeoutSeconds,
                ]);
                $content = $response->getContent();

                // Wrap in minimal HTML if it's markdown or plain text so downstream tooling can render it
                return \sprintf('<pre>%s</pre>', htmlspecialchars($content));
            } catch (\Throwable $e) {
                // Fall through to Puppeteer if HTTP fetch fails (e.g. missing README or rate limits)
                // (e.g., 404 if README doesn't exist on that branch)
            }
        }

        // Fall back to Puppeteer for other URLs or if GitHub fetch failed
        return $this->fetchWithPuppeteer($url);
    }

    /**
     * Convert GitHub web URLs to raw.githubusercontent.com URLs
     * Only converts URLs that point to actual file content.
     */
    private function convertGitHubUrl(string $url): ?string
    {
        // Match: https://github.com/{owner}/{repo}/blob/{branch}/{path}
        // This is a file view - safe to convert
        if (preg_match('#^https://github\.com/([^/]+)/([^/]+)/blob/([^/]+)/(.+)$#', $url, $matches)) {
            [, $owner, $repo, $branch, $path] = $matches;

            return \sprintf('https://raw.githubusercontent.com/%s/%s/%s/%s', $owner, $repo, $branch, $path);
        }

        // Match: https://github.com/{owner}/{repo}/?$ (with optional trailing slash, no other segments)
        // This is a repository home - safe to fetch README
        if (preg_match('#^https://github\.com/([^/]+)/([^/]+)/?$#', $url, $matches)) {
            [, $owner, $repo] = $matches;

            // Try to fetch README (HEAD follows the default branch)
            return \sprintf('https://raw.githubusercontent.com/%s/%s/HEAD/README.md', $owner, $repo);
        }

        // Don't convert tree URLs, issues, PRs, discussions, etc.
        // Let Puppeteer handle those
        return null;
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
