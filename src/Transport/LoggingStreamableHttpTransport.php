<?php

declare(strict_types=1);

namespace App\Transport;

use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class LoggingStreamableHttpTransport extends StreamableHttpTransport
{
    public function __construct(
        ServerRequestInterface $request,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        array $corsHeaders = [],
        ?LoggerInterface $logger = null,
        iterable $middleware = [],
    ) {
        parent::__construct($request, $responseFactory, $streamFactory, $corsHeaders, $logger, $middleware);
    }

    public function send(string $data, array $context): void
    {
        $decoded = json_decode($data, true);
        if (\JSON_ERROR_NONE === json_last_error()) {
            $this->logger->info('Sending HTTP immediate response', ['message' => $decoded]);
        } else {
            $this->logger->info('Sending HTTP immediate response', ['message' => $data]);
        }
        parent::send($data, $context);
    }

    protected function getOutgoingMessages(?Uuid $sessionId): array
    {
        $messages = parent::getOutgoingMessages($sessionId);

        foreach ($messages as $message) {
            $decoded = json_decode($message['message'], true);
            if (\JSON_ERROR_NONE === json_last_error()) {
                $this->logger->info('Sending HTTP queued response', ['message' => $decoded]);
            } else {
                $this->logger->info('Sending HTTP queued response', ['message' => $message['message']]);
            }
        }

        return $messages;
    }
}
