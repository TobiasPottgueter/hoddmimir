<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\ExecutorEvidence;

use App\Infrastructure\Proxmox\PveTlsConfiguration;
use Symfony\Contracts\HttpClient\HttpClientInterface;

interface PveExecutorEvidenceHttpClientFactory
{
    public function create(PveTlsConfiguration $tls): HttpClientInterface;
}
