<?php

declare(strict_types=1);

namespace App\Tools;

use App\Service\BrowserService;

final class SearchTool
{
    public const NAME = 'search';
    public const TITLE = 'Search for information';

    public const DESCRIPTION = 'Searches for information related to `query` and displays `topn` results.';

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
