#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * HTTP worker script for the browser-mcp Streamable HTTP transport.
 *
 * Used as a router script for PHP's built-in web server:
 *   php -S <host>:<port> /path/to/http-worker.php
 *
 * Booted by BrowserMcpCommand when MCP_TRANSPORT=http.
 */

use App\Kernel;
use App\Server\ServerFactory;
use App\Transport\LoggingStreamableHttpTransport;
use App\Transport\ResponseEmitter;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7Server\ServerRequestCreator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;

if (!defined('STDIN')) {
    define('STDIN', fopen('php://stdin', 'r'));
}
if (!defined('STDOUT')) {
    define('STDOUT', fopen('php://stdout', 'w'));
}
if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'w'));
}

if (PHP_SAPI !== 'cli-server') {
    fwrite(STDERR, "This script must be run via PHP built-in server (cli-server SAPI).\n");
    exit(1);
}

// Resolve the project root.
// Priority:
//  1. APP_PHAR_PATH  – raw path to the PHAR/static binary; wrap in phar://
//  2. APP_PROJECT_DIR – already-resolved path (may already be phar://)
//  3. Fallback to parent of __DIR__
$pharPath = getenv('APP_PHAR_PATH');
if ($pharPath) {
    // Wrap the raw binary/phar path so PHP can read files from inside it
    $projectDir = 'phar://' . $pharPath;
} else {
    $projectDir = getenv('APP_PROJECT_DIR') ?: dirname(__DIR__);
    // If APP_PROJECT_DIR points to a file (not a directory or phar url), wrap it
    if (!str_starts_with($projectDir, 'phar://') && is_file($projectDir)) {
        $projectDir = 'phar://' . $projectDir;
    }
}
$projectDir = rtrim($projectDir, '/');

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
/** @var LoggerInterface $logger */
$logger = $container->get(LoggerInterface::class);
$server = $factory->create();

$psr17Factory = new Psr17Factory();
$creator = new ServerRequestCreator(
    $psr17Factory,
    $psr17Factory,
    $psr17Factory,
    $psr17Factory,
);
$request = $creator->fromGlobals();

$transport = new LoggingStreamableHttpTransport(
    request: $request,
    responseFactory: $psr17Factory,
    streamFactory: $psr17Factory,
    logger: $logger,
);

$response = $server->run($transport);

(new ResponseEmitter())->emit($response);
