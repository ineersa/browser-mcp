<?php

declare(strict_types=1);

namespace App\Tools;

use App\Service\BrowserService;

final class OpenTool
{
    public function __construct(
        private readonly BrowserService $pythonService,
    ) {
    }

    /**
     * Entry point for browser tool.
     *
     * @return array{result: string}
     */
    public function __invoke(string $code): array
    {
        return [];
    }
}
