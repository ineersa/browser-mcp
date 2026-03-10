<?php

declare(strict_types=1);

namespace App\Service\Contracts;

use App\Domain\Read\ReadDocument;
use App\Domain\Read\ReadRequest;

interface ReaderContract extends ProviderContract
{
    public function read(ReadRequest $request): ReadDocument;
}
