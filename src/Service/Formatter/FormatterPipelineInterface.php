<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Domain\Format\FormatContext;
use App\Domain\Format\FormatPayload;

interface FormatterPipelineInterface
{
    public function process(FormatPayload $payload, FormatContext $context): FormatPayload;
}
