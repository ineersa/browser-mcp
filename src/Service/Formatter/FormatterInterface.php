<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Domain\Format\FormatPayload;

interface FormatterInterface
{
    public function format(FormatPayload $payload): FormatPayload;
}
