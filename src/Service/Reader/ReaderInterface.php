<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Domain\Read\ReadDocument;
use App\Domain\Read\ReadRequest;

interface ReaderInterface
{
    public function read(ReadRequest $request): ReadDocument;
}
