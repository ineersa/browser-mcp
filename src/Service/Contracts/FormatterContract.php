<?php

declare(strict_types=1);

namespace App\Service\Contracts;

use App\Domain\Format\FormatPayload;

interface FormatterContract
{
    public function format(FormatPayload $payload): FormatPayload;
}
