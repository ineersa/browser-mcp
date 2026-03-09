<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Domain\Format\FormatContext;

interface FormatterPipelineInterface
{
    public function addFormatter(string|FormatterInterface $formatter): self;

    public function format(FormatContext $context): FormatContext;
}
