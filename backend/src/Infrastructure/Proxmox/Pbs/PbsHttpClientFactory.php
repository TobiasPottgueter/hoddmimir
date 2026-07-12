<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use Symfony\Contracts\HttpClient\HttpClientInterface;

interface PbsHttpClientFactory
{
    public function create(PbsTlsConfiguration $tls): HttpClientInterface;
}
