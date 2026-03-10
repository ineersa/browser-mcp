<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\AppConfig;
use App\Service\ServerFactory;
use App\Transport\LoggingStdioTransport;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'browser-mcp',
    description: 'Run the browser MCP server (STDIO or HTTP)',
)]
class BrowserMcpCommand extends Command
{
    private string $projectDir;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly AppConfig $config,
        private readonly ServerFactory $serverFactory,
        string $projectDir,
    ) {
        if (!is_dir($projectDir) && !$this->isPharPath($projectDir)) {
            $projectDir = \dirname(__DIR__, 2);
        }
        $this->projectDir = $projectDir;
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $transport = $this->config->getTransport();

        try {
            if ('http' === $transport) {
                return $this->runHttp($output);
            }

            return $this->runStdio($output);
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'trace' => $e->getTrace(),
            ]);
            \assert($output instanceof ConsoleOutputInterface);
            $output->getErrorOutput()->writeln(json_encode([
                'error' => $e->getMessage(),
            ]));

            return Command::FAILURE;
        }
    }

    private function runStdio(OutputInterface $output): int
    {
        $server = $this->serverFactory->create();

        $transport = new LoggingStdioTransport(
            \STDIN,
            \STDOUT,
            $this->logger,
        );

        $server->run($transport);

        return Command::SUCCESS;
    }

    private function runHttp(OutputInterface $output): int
    {
        $port = $this->config->getPort();
        $workerPath = $this->resolveWorkerPath();

        $env = $_SERVER;
        $env['APP_PROJECT_DIR'] = $this->projectDir;
        // For PHAR/static binaries, also pass the raw file path so the worker
        // can build the correct phar:// URL for autoloading.
        if ($this->isPharPath($this->projectDir)) {
            $pharPath = $this->resolvePharPath();
            if ('' !== $pharPath) {
                $env['APP_PHAR_PATH'] = $pharPath;
            }
        }
        foreach ($env as $key => $value) {
            if (!\is_scalar($value)) {
                unset($env[$key]);
            }
        }

        $cwd = $this->isPharPath($this->projectDir) ? null : $this->projectDir;

        $phpBinary = is_executable(\PHP_BINARY) ? \PHP_BINARY : 'php';

        $process = new Process(
            [$phpBinary, '-S', \sprintf('127.0.0.1:%d', $port), $workerPath],
            $cwd,
            $env,
        );

        $process->setTimeout(null);

        $this->logger->info(\sprintf('Starting HTTP server on 127.0.0.1:%d', $port));

        \assert($output instanceof ConsoleOutputInterface);
        $errorOutput = $output->getErrorOutput();

        $process->start(static function (string $type, string $buffer) use ($errorOutput): void {
            $errorOutput->write($buffer);
        });

        $process->wait();

        return Command::SUCCESS;
    }

    private function resolveWorkerPath(): string
    {
        $workerPath = $this->projectDir.'/bin/http-worker.php';

        if (!$this->isPharPath($workerPath)) {
            return $workerPath;
        }

        return $this->extractWorkerToTemp($workerPath);
    }

    private function extractWorkerToTemp(string $pharPath): string
    {
        $contents = @file_get_contents($pharPath);
        if (false === $contents) {
            throw new \RuntimeException(\sprintf('Unable to read HTTP worker script from PHAR at %s', $pharPath));
        }

        $baseDir = $_SERVER['APP_VAR_DIR'] ?? $_ENV['APP_VAR_DIR'] ?? getenv('APP_VAR_DIR') ?: sys_get_temp_dir();
        $tmpPath = rtrim($baseDir, '/\\').'/browser-mcp-'.sha1($pharPath).'-http-worker.php';

        $filesystem = new Filesystem();
        try {
            $filesystem->dumpFile($tmpPath, $contents);
        } catch (IOExceptionInterface $e) {
            throw new \RuntimeException(\sprintf('Unable to write HTTP worker script to %s', $tmpPath), 0, $e);
        }

        return $tmpPath;
    }

    private function isPharPath(string $path): bool
    {
        return str_starts_with($path, 'phar://') || str_starts_with($path, 'phar:');
    }

    /**
     * Returns the raw filesystem path to the PHAR archive that the worker should
     * load via phar://.  For plain .phar files Phar::running(false) is correct.
     * For spc micro:combine static binaries the running path points to the fused
     * binary which is NOT a valid phar:// stream from an external PHP process.
     * In that case we fall back to the sibling <binary>.phar file that box
     * produced before the combine step.
     */
    private function resolvePharPath(): string
    {
        $candidates = [];

        $running = \Phar::running(false);
        if ('' !== $running) {
            $candidates[] = $running;
        }

        $projectDirCandidate = $this->extractPharCandidateFromProjectDir();
        if ('' !== $projectDirCandidate) {
            $candidates[] = $projectDirCandidate;
        }

        foreach (array_unique($candidates) as $candidate) {
            if (str_ends_with($candidate, '.phar') && is_file($candidate)) {
                return $candidate;
            }

            $sibling = $candidate.'.phar';
            if (is_file($sibling)) {
                return $sibling;
            }

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function extractPharCandidateFromProjectDir(): string
    {
        if (!$this->isPharPath($this->projectDir)) {
            return '';
        }

        $path = preg_replace('#^phar:(//)?#', '', $this->projectDir);
        if (!\is_string($path) || '' === $path) {
            return '';
        }

        $pharPosition = strpos($path, '.phar');

        if (false !== $pharPosition) {
            return substr($path, 0, $pharPosition + 5);
        }

        return rtrim($path, '/');
    }
}
