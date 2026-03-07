<?php

declare(strict_types=1);

namespace App\Service;

use App\Tools\FindTool;
use App\Tools\OpenTool;
use App\Tools\SearchTool;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final readonly class ServerFactory
{
    public function __construct(
        private LoggerInterface $logger,
        private ContainerInterface $container,
        private string $cacheDir,
    ) {
    }

    public function create(): Server
    {
        return Server::builder()
            ->setServerInfo(
                name: 'browser',
                version: '0.0.1',
                description: 'Provides MCP tools for searching, opening, and finding information within web documents.'
            )
            ->setLogger($this->logger)
            ->setContainer($this->container)
            ->setSession(new FileSessionStore($this->cacheDir.'/mcp_sessions'))
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
    }
}
