<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\PveBackup;

use App\Application\Proxmox\Pve\BuildPveVzdumpPayload;
use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveBackupClient;
use App\Application\Proxmox\Pve\PveBackupJobCapabilities;
use App\Application\Proxmox\Pve\PveVersion;
use App\Application\Security\SecretCipher;
use App\Infrastructure\Proxmox\PveApiUrlBuilder;
use App\Infrastructure\Proxmox\PveHttpClientFactory;
use App\Infrastructure\Proxmox\PveRetryDelay;
use App\Infrastructure\Proxmox\PveRetryPolicy;
use App\Infrastructure\Proxmox\PveTaskStatusReader;
use InvalidArgumentException;
use RuntimeException;

final readonly class PveNativeBackupClientFactory implements PveBackupClientFactory
{
    public function __construct(
        private PveHttpClientFactory $httpClientFactory,
        private SecretCipher $secretCipher,
        private PveRetryPolicy $retryPolicy,
        private PveRetryDelay $retryDelay,
    ) {
    }

    public function create(PveBackupEndpointConfiguration $configuration, PveVersion $version): PveBackupClient
    {
        try {
            PveBackupJobCapabilities::forMajor($version->major);
            $httpClient = $this->httpClientFactory->create($configuration->tls);
        } catch (InvalidArgumentException|RuntimeException) {
            throw PveBackupApiFailure::for(
                match ($version->major) {
                    7, 8, 9 => PveBackupApiFailureCode::Configuration,
                    default => PveBackupApiFailureCode::UnsupportedVersion,
                },
            );
        }
        $transport = new PveBackupHttpTransport(
            $httpClient,
            new PveApiUrlBuilder($configuration->host, $configuration->port),
            $configuration->authenticator($this->secretCipher),
            $this->retryPolicy,
            $this->retryDelay,
            new PveBackupJsonEnvelopeDecoder(),
        );

        return new PveHttpBackupClient(
            $transport,
            $version,
            new BuildPveVzdumpPayload(),
            new PveBackupSubmissionReader(),
            new PveTaskStatusReader($version),
            new PveBackupTaskLogReader(),
        );
    }
}
