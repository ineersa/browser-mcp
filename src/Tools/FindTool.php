<?php

declare(strict_types=1);

namespace App\Tools;

use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\FindService;

final class FindTool
{
    public const string NAME = 'find';
    public const string TITLE = 'Find pattern in page';
    public const string DESCRIPTION = 'Finds exact matches of `pattern` in the current page, or the page given by `cursor`. Provide `regex` to run a regex match instead.';

    public function __construct(
        private readonly FindService $findService,
    ) {
    }

    /**
     * @return array{result: string}
     */
    public function __invoke(?string $pattern = null, ?string $regex = null, int $cursor = -1): string
    {
        try {
            $result = $this->findService->__invoke(pattern: $pattern, regex: $regex, cursor: $cursor);
            return $result;
        } catch (ToolUsageError|BackendError $exception) {
            return "Result: error\n Error Message: " . $exception->getMessage() . "\n Hint: " . $exception->getHint();
        }
    }
}
