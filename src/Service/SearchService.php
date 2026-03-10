<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Format\FormatContext;
use App\Domain\Search\SearchRequest;
use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\Formatter\FormatterChain;
use App\Service\Formatter\LegacyDisplayFormatterPipelineAdapter;
use App\Service\Formatter\TextSearchOutputFormatter;
use App\Service\Searcher\SearcherInterface;

final readonly class SearchService
{
    public function __construct(
        private SearcherInterface $searcher,
        private BrowserState $state,
        private TextSearchOutputFormatter $searchResultFormatter,
        private LegacyDisplayFormatterPipelineAdapter $displayFormatter,
    ) {
    }

    /**
     * @throws BackendError
     * @throws ToolUsageError
     */
    public function __invoke(string $query, int $topn = 5): string
    {
        if (empty($query)) {
            throw new ToolUsageError('query cannot be empty')->setHint('Provide query to search');
        }

        if ($topn < 1 || $topn > 10) {
            throw new ToolUsageError("topn can't be less than 1 and more than 10")->setHint('Provide topn in range 1-10');
        }

        try {
            $resultSet = $this->searcher->search(new SearchRequest(query: $query, limit: $topn));
        } catch (\Throwable $e) {
            $msg = Utilities::maybeTruncate($e->getMessage());
            throw new BackendError(\sprintf('Error during search for `%s`: %s', $query, $msg), previous: $e)->setHint('This may be a backend service error or network timeout. Try retrying the search request.');
        }
        $this->state->reset();

        $chain = new FormatterChain();
        $chain
            ->addFormatter($this->searchResultFormatter)
            ->addFormatter($this->displayFormatter);

        $formatted = $chain->format(new FormatContext(
            tool: 'search',
            startLine: 0,
            numberOfLines: -1,
            document: $resultSet,
        ));

        return $formatted->output;
    }
}
