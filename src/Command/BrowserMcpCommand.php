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
        private LoggerInterface $logger,
        private ContainerInterface $container,
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
            $serverDescription = <<<DESC
Tool for browsing.
The `cursor` appears in brackets before each browsing display: `[CURSOR:#{cursor}]`.
Cite information from the tool using the following format:
`【{cursor}†L{line_start}(-L{line_end})?】`, for example: `【6†L9-L11】` or `【8†L3】`.
Do not quote more than 10 words directly from the tool output.
DESC;
            // Build server configuration
            $server = Server::make()
                ->withServerInfo(
                    name: 'browser',
                    version: '0.0.1',
                    description: $serverDescription
                )
                ->withLogger($this->logger)
                ->withContainer($this->container)
                ->withTool(
                    handler: SearchTool::class,
                    name: SearchTool::NAME,
                    description: SearchTool::DESCRIPTION,
                    annotations: new ToolAnnotations(
                        title: SearchTool::TITLE,
                    )
                )
                ->withTool(
                    handler: OpenTool::class,
                    name: OpenTool::NAME,
                    description: OpenTool::DESCRIPTION,
                    annotations: new ToolAnnotations(
                        title: OpenTool::TITLE,
                    )
                )
                ->withTool(
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
