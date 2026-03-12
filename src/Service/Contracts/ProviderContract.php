<?php

declare(strict_types=1);

namespace App\Service\Contracts;

interface ProviderContract
{
    public function getProvider(): string;
}
