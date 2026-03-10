<?php

declare(strict_types=1);

namespace App\Service\Contracts;

use App\Domain\Search\SearchRequest;
use App\Domain\Search\SearchResultSet;

interface SearcherContract extends ProviderContract
{
    public function search(SearchRequest $request): SearchResultSet;
}
