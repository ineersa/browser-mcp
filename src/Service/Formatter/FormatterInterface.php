<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Domain\Format\FormatContext;

interface FormatterInterface
{
    public function format(FormatContext $context): FormatContext;
}
