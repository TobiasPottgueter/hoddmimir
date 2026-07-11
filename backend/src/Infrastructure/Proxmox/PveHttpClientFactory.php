<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use Symfony\Contracts\HttpClient\HttpClientInterface;

interface PveHttpClientFactory
{
    public function create(PveTlsConfiguration $tls): HttpClientInterface;
}
