<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpReader extends SearxNGReader
{
    public function __construct(HttpClientInterface $client)
    {
        parent::__construct($client);
    }

    protected function fetchHtml(string $url): string
    {
        return $this->httpGet($url);
    }
}
