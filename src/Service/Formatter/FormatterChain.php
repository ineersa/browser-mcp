<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Domain\Format\FormatPayload;

final class FormatterChain
{
    /**
     * @var list<FormatterInterface>
     */
    private array $formatters = [];

    public function addFormatter(FormatterInterface $formatter): self
    {
        $this->formatters[] = $formatter;

        return $this;
    }

    public function format(FormatPayload $payload): FormatPayload
    {
        foreach ($this->formatters as $formatter) {
            $payload = $formatter->format($payload);
        }

        return $payload;
    }
}
