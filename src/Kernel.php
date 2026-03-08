<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    public function getLogDir(): string
    {
        return $this->getVarDir().'/log';
    }

    public function getCacheDir(): string
    {
        return $this->getVarDir().'/cache/'.$this->environment;
    }

    private function getVarDir(): string
    {
        $dir = $_SERVER['APP_VAR_DIR']
            ?? $_ENV['APP_VAR_DIR']
            ?? getenv('APP_VAR_DIR');

        if ($dir) {
            return rtrim($dir, '/');
        }

        return $this->getProjectDir().'/var';
    }
}
