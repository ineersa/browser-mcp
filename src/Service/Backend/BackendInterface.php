<?php

declare(strict_types=1);

namespace App\Service\Backend;

use App\Service\DTO\PageContents;

interface BackendInterface
{

    /** Perform a search and return a synthetic PageContents representing results. */
    public function search(string $query, int $topn): PageContents;

    /** Fetch and convert a URL to PageContents. */
    public function fetch(string $url): PageContents;
}
