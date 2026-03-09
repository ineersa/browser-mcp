<?php

declare(strict_types=1);

namespace App\Service;

interface ProviderInterface
{
    public function getProvider(): string;
}
