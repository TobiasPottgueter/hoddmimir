<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\ExecutorEvidence;

use App\Infrastructure\Proxmox\PveNativeHttpClientFactory;
use App\Infrastructure\Proxmox\PveTlsConfiguration;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class PveNativeExecutorEvidenceHttpClientFactory implements PveExecutorEvidenceHttpClientFactory
{
    public function __construct(private PveNativeHttpClientFactory $factory)
    {
    }

    public function create(PveTlsConfiguration $tls): HttpClientInterface
    {
        return $this->factory->create($tls);
    }
}
