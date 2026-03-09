<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Domain\Read\ReadDocument;
use App\Domain\Read\ReadRequest;
use App\Service\ProviderInterface;

interface ReaderInterface extends ProviderInterface
{
    public function read(ReadRequest $request): ReadDocument;
}
