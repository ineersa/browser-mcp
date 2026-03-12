<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Find\FindMatchMode;
use App\Domain\Read\ReadRequest;
use App\Service\Exception\BackendError;
use App\Service\Exception\ToolUsageError;
use App\Service\FindService;
use App\Service\OpenService;
use App\Service\Contracts\ReaderContract;
use App\Service\SearchService;
use App\Service\Utilities;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:repl',
    description: 'Interactive REPL for search, reader, open, and find services.',
)]
final class ReplCommand extends Command
{
    public function __construct(
        private readonly SearchService $searchService,
        private readonly ReaderContract $reader,
        private readonly OpenService $openService,
        private readonly FindService $findService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Browser MCP Interactive REPL');
        $io->writeln('<fg=gray>Choose a mode, run it, inspect raw output, then continue or quit.</>');

        while (true) {
            $mode = $this->askMode($io);
            if ('quit' === $mode) {
                $io->success('Bye.');

                return Command::SUCCESS;
            }

            $io->newLine();
            $io->section(strtoupper($mode).' mode');

            try {
                $result = match ($mode) {
                    'search' => $this->runSearch($io),
                    'reader' => $this->runReader($io),
                    'open' => $this->runOpen($io),
                    'find' => $this->runFind($io),
                    default => throw new \LogicException('Unsupported mode selected.'),
                };

                $io->success('Request completed');
                $io->writeln('<options=bold;fg=cyan>Raw response</>');
                $io->writeln($result);
            } catch (ToolUsageError|BackendError $e) {
                $io->error($e->getMessage());
                if ('' !== $e->getHint()) {
                    $io->writeln('<comment>Hint:</comment> '.$e->getHint());
                }
            } catch (\Throwable $e) {
                $io->error($e->getMessage());
            }

            if (!$io->confirm('Run another request?', true)) {
                $io->success('Bye.');

                return Command::SUCCESS;
            }

            $io->newLine();
        }
    }

    private function askMode(SymfonyStyle $io): string
    {
        $io->writeln('<comment>Modes:</comment> search, reader, open, find');

        return $io->choice(
            question: 'Choose mode',
            choices: ['search', 'reader', 'open', 'find', 'quit'],
            default: 'search',
        );
    }

    /**
     * @throws ToolUsageError
     * @throws BackendError
     */
    private function runSearch(SymfonyStyle $io): string
    {
        $io->writeln('<fg=gray>Input: query (topn=5 default).</>');
        $query = trim((string) $io->ask('Query', 'symfony mcp'));
        if ('' === $query) {
            throw new ToolUsageError('Query cannot be empty.')->setHint('Provide query text for search mode.');
        }

        return $this->searchService->__invoke($query, 5);
    }

    /**
     * @throws ToolUsageError
     * @throws BackendError
     */
    private function runReader(SymfonyStyle $io): string
    {
        $io->writeln('<fg=gray>Input: URL. Uses the configured reader from env and prints markdown.</>');
        $url = trim((string) $io->ask('URL', 'https://example.com'));
        $canonicalUrl = Utilities::canonicalizeUrl($url);
        if ('' === $canonicalUrl) {
            throw new ToolUsageError('Invalid URL provided.')->setHint('Provide an absolute URL, e.g. `https://example.com/article`.');
        }

        $doc = $this->reader->read(new ReadRequest(url: $canonicalUrl, canonicalUrl: $canonicalUrl));

        return $doc->markdown;
    }

    /**
     * @throws ToolUsageError
     * @throws BackendError
     */
    private function runOpen(SymfonyStyle $io): string
    {
        $io->writeln('<fg=gray>Input: URL, then choose auto/window/full.</>');
        $url = trim((string) $io->ask('URL', 'https://example.com'));

        $mode = $io->choice(
            question: 'Open mode',
            choices: ['auto', 'window', 'full'],
            default: 'auto',
        );

        if ('full' === $mode) {
            return $this->openService->__invoke($url, 0, -1, true);
        }

        if ('auto' === $mode) {
            return $this->openService->__invoke($url);
        }

        $startAtLine = max(0, (int) $io->ask('Start at line (0-based)', '0'));
        $numberOfLines = max(1, (int) $io->ask('Number of lines', '50'));

        return $this->openService->__invoke($url, $startAtLine, $numberOfLines, false);
    }

    /**
     * @throws ToolUsageError
     * @throws BackendError
     */
    private function runFind(SymfonyStyle $io): string
    {
        $io->writeln('<fg=gray>Input: URL, query, match mode.</>');
        $url = trim((string) $io->ask('URL', 'https://example.com'));
        $query = trim((string) $io->ask('Query', 'install'));

        if ('' === $query) {
            throw new ToolUsageError('Find query cannot be empty.')->setHint('Provide plain text query for find mode.');
        }

        $match = $io->choice(
            question: 'Match mode',
            choices: [FindMatchMode::CONTAINS->value, FindMatchMode::EXACT->value],
            default: FindMatchMode::CONTAINS->value,
        );

        $matchMode = FindMatchMode::from($match);

        return $this->findService->__invoke($url, $query, $matchMode, 5);
    }
}
