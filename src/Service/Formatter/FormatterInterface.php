<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Domain\Format\FormatContext;
use App\Domain\Format\FormatPayload;

interface FormatterInterface
{
    public function supports(FormatContext $context): bool;

    public function format(FormatPayload $payload, FormatContext $context): FormatPayload;
}
