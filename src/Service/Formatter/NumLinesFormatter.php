<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Domain\Format\FormatPayload;
use App\Domain\Read\ReadDocument;
use App\Service\Contracts\FormatterContract;
use App\Service\Exception\ToolUsageError;
use App\Service\Utilities;

final readonly class NumLinesFormatter implements FormatterContract
{
    public function __construct(
        private int $startAtLine,
        private int $numberOfLines,
        private bool $fetchAll = false,
    ) {
    }

    /**
     * @throws ToolUsageError
     */
    public function format(FormatPayload $payload): FormatPayload
    {
        if (!$payload->document instanceof ReadDocument) {
            return $payload;
        }

        $lines = Utilities::wrapLines($payload->document->markdown);
        while (!empty($lines) && '' === $lines[\count($lines) - 1]) {
            array_pop($lines);
        }

        $totalLines = \count($lines);
        $startLine = max(0, $this->startAtLine);
        if ($startLine >= $totalLines) {
            throw new ToolUsageError(\sprintf('Invalid start_at_line parameter: `%d`. Cannot exceed page maximum of %d.', $startLine, max(0, $totalLines - 1)))->setHint('Choose a smaller `start_at_line` within the page line count.');
        }

        $endLine = $this->fetchAll
            ? $totalLines
            : min($startLine + max($this->numberOfLines, 1), $totalLines);

        $offset = $this->fetchAll ? 0 : $startLine;
        $linesToShow = $this->fetchAll ? $lines : \array_slice($lines, $startLine, $endLine - $startLine);

        $working = $payload->working;
        $working['body'] = Utilities::joinLines($linesToShow, true, $offset);
        $working['totalLines'] = $totalLines;
        $working['startLine'] = $offset;
        $working['endLine'] = $endLine;

        return new FormatPayload(
            document: $payload->document,
            output: $payload->output,
            working: $working,
        );
    }
}
