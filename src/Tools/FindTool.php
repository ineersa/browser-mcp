<?php

declare(strict_types=1);

namespace App\Tools;

use App\Service\Exception\ToolUsageError;
use App\Service\FindService;

final class FindTool
{
    public const string NAME = 'find';
    public const string TITLE = 'Find pattern in page';
    public const string DESCRIPTION = 'Finds exact matches of `pattern` in the current page, or the page given by `cursor`.';

    public function __construct(
        private readonly FindService $findService,
    ) {
    }

    /**
     * @return array{result: string}
     */
    public function __invoke(string $pattern, int $cursor = -1): array
    {
        try {
            $result = $this->findService->__invoke($pattern, $cursor);
        } catch (ToolUsageError $exception) {
            $result = $exception->getMessage();
        }

        return ['result' => $result];
    }
}
