<?php

declare(strict_types=1);

namespace App\Tools;

use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\SearchService;

final class SearchTool
{
    public const string NAME = 'search';
    public const string TITLE = 'Search for information';
    public const string DESCRIPTION = 'Searches for information related to `query` and displays `topn` results.';

    public function __construct(
        private readonly SearchService $searchService,
    ) {
    }

    /**
     * @return array{result: string}
     */
    public function __invoke(
        string $query,
        int $topn = 10,
    ): array {
        try {
            $result = $this->searchService->__invoke($query, $topn);
        } catch (ToolUsageError|BackendError $exception) {
            $result = $exception->getMessage();
        }

        return ['result' => $result];
    }
}
