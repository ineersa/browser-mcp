<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Domain\Format\FormatPayload;

interface FormatterPipelineInterface
{
    public function addFormatter(FormatterInterface $formatter): self;

    public function format(FormatPayload $payload): FormatPayload;
}
