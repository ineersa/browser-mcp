<?php

declare(strict_types=1);

namespace App\Tools;

final class FindTool
{
    public const NAME = 'find';
    public const TITLE = 'Find pattern in page';
    public const DESCRIPTION = 'Finds exact matches of `pattern` in the current page, or the page given by `cursor`.';

    public function __construct()
    {
    }

    /**
     * @return array{result: string}
     */
    public function __invoke(string $pattern, int $cursor = -1): array
    {
        return [];
    }
}
