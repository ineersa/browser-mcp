<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Domain\Format\FormatPayload;
use App\Service\Contracts\FormatterContract;

final class FormatterChain
{
    /**
     * @var list<FormatterContract>
     */
    private array $formatters = [];

    public function addFormatter(FormatterContract $formatter): self
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
