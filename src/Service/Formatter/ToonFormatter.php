<?php

declare(strict_types=1);

namespace App\Service\Formatter;

use App\Domain\Format\FormatPayload;
use App\Service\Contracts\FormatterContract;
use HelgeSverre\Toon\Toon;

final class ToonFormatter implements FormatterContract
{
    public function format(FormatPayload $payload): FormatPayload
    {
        return new FormatPayload(
            document: $payload->document,
            output: Toon::encode($payload->document),
            working: $payload->working,
        );
    }
}
