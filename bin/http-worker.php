#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * HTTP worker script for the browser-mcp Streamable HTTP transport.
 *
 * Used as a router script for PHP's built-in web server:
 *   php -S 127.0.0.1:<port> /path/to/http-worker.php
 *
 * Booted by BrowserMcpCommand when MCP_TRANSPORT=http.
 */

use App\Kernel;
use App\Service\ServerFactory;
use App\Transport\ResponseEmitter;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7Server\ServerRequestCreator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bundle\FrameworkBundle\Console\Application;

if (PHP_SAPI !== 'cli-server') {
    fwrite(STDERR, "This script must be run via PHP built-in server (cli-server SAPI).\n");
    exit(1);
}

// Resolve the project root: when extracted to tmp the __DIR__ is the temp dir,
// so we rely on APP_PROJECT_DIR env var set by BrowserMcpCommand::execute().
$projectDir = getenv('APP_PROJECT_DIR') ?: (getenv('DOCUMENT_ROOT') ?: __DIR__ . '/..');

$autoloadFile = $projectDir . '/vendor/autoload.php';
if (!is_file($autoloadFile)) {
    fwrite(STDERR, sprintf("Cannot find autoload at %s\n", $autoloadFile));
    exit(1);
}
require_once $autoloadFile;

// Boot the Symfony kernel
$appEnv = getenv('APP_ENV') ?: 'prod';
$appDebug = (bool) (getenv('APP_DEBUG') ?: false);

$kernel = new Kernel($appEnv, $appDebug);
$kernel->boot();

$container = $kernel->getContainer();

/** @var ServerFactory $factory */
$factory = $container->get(ServerFactory::class);
$server = $factory->create();

$psr17Factory = new Psr17Factory();
$creator = new ServerRequestCreator(
    $psr17Factory,
    $psr17Factory,
    $psr17Factory,
    $psr17Factory,
);
$request = $creator->fromGlobals();

$transport = new StreamableHttpTransport(
    $request,
    $psr17Factory,
    $psr17Factory,
);

$response = $server->run($transport);

(new ResponseEmitter())->emit($response);
