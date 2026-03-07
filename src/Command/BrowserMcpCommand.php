<?php

declare(strict_types=1);

namespace App\Command;

use App\Tools\FindTool;
use App\Tools\OpenTool;
use App\Tools\SearchTool;
use App\Transport\LoggingStdioTransport;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'browser-mcp',
    description: 'Add a short description for your command',
)]
class BrowserMcpCommand extends Command
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ContainerInterface $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Build server configuration
            $server = Server::builder()
                ->setServerInfo(
                    name: 'browser',
                    version: '0.0.1',
                    description: 'Provides MCP tools for searching, opening, and finding information within web documents.'
                )
                ->setLogger($this->logger)
                ->setContainer($this->container)
                ->setProtocolVersion(ProtocolVersion::V2025_06_18)
                ->addTool(
                    SearchTool::class,
                    SearchTool::NAME,
                    SearchTool::DESCRIPTION,
                    new ToolAnnotations(
                        title: SearchTool::TITLE,
                    )
                )
                ->addTool(
                    OpenTool::class,
                    OpenTool::NAME,
                    OpenTool::DESCRIPTION,
                    new ToolAnnotations(
                        title: OpenTool::TITLE,
                    )
                )
                ->addTool(
                    FindTool::class,
                    FindTool::NAME,
                    FindTool::DESCRIPTION,
                    new ToolAnnotations(
                        title: FindTool::TITLE,
                    )
                )
                ->build();

            $transport = new LoggingStdioTransport(
                \STDIN,
                \STDOUT,
                $this->logger,
            );

            $server->run($transport);
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

        return Command::SUCCESS;
    }
}
