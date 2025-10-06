<?php

declare(strict_types=1);

namespace App\Command;

use App\Tools\FindTool;
use App\Tools\OpenTool;
use App\Tools\SearchTool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
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

    /**
     * @param ConsoleOutput $output
     */
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
                ->addTool(
                    handler: SearchTool::class,
                    name: SearchTool::NAME,
                    description: SearchTool::DESCRIPTION,
                    annotations: new ToolAnnotations(
                        title: SearchTool::TITLE,
                    )
                )
                ->addTool(
                    handler: OpenTool::class,
                    name: OpenTool::NAME,
                    description: OpenTool::DESCRIPTION,
                    annotations: new ToolAnnotations(
                        title: OpenTool::TITLE,
                    )
                )
                ->addTool(
                    handler: FindTool::class,
                    name: FindTool::NAME,
                    description: FindTool::DESCRIPTION,
                    annotations: new ToolAnnotations(
                        title: FindTool::TITLE,
                    )
                )
                ->build();

            $transport = new StdioTransport(
                logger: $this->logger,
            );

            $server->connect($transport);
            $transport->listen();
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'trace' => $e->getTrace(),
            ]);
            $output->getErrorOutput()->writeln(json_encode([
                'error' => $e->getMessage(),
            ]));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
