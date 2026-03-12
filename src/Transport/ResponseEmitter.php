<?php

declare(strict_types=1);

namespace App\Transport;

use Psr\Http\Message\ResponseInterface;

final class ResponseEmitter
{
    // @phpstan-ignore shipmonk.deadMethod
    public function emit(ResponseInterface $response): void
    {
        http_response_code($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header(\sprintf('%s: %s', $name, $value), false);
            }
        }

        // Reading the body triggers CallbackStream for SSE (echo + flush internally)
        echo (string) $response->getBody();
    }
}
