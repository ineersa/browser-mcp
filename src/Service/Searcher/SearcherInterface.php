<?php

declare(strict_types=1);

namespace App\Service\Searcher;

use App\Domain\Search\SearchRequest;
use App\Domain\Search\SearchResultSet;
use App\Service\ProviderInterface;

interface SearcherInterface extends ProviderInterface
{
    public function search(SearchRequest $request): SearchResultSet;
}
