<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Proxmox\Pve\PveReadConnector;
use App\Application\Security\SecretCipher;
use InvalidArgumentException;
use RuntimeException;

final readonly class PveNativeCoreReadConnectorFactory implements PveCoreReadConnectorFactory
{
    public function __construct(
        private PveHttpClientFactory $httpClientFactory,
        private SecretCipher $secretCipher,
        private PveRetryPolicy $retryPolicy,
        private PveRetryDelay $retryDelay,
    ) {
    }

    public function create(
        PveEndpointReadConfiguration $configuration,
        ConnectionReadCheckpoint $checkpoint,
    ): PveReadConnector {
        try {
            $httpClient = $this->httpClientFactory->create($configuration->tls);
        } catch (InvalidArgumentException|RuntimeException) {
            throw new PveCoreReadConnectorFactoryFailure();
        }

        $transport = new PveHttpTransport(
            $httpClient,
            new PveApiUrlBuilder($configuration->host, $configuration->port),
            $configuration->authenticator($this->secretCipher),
            $this->retryPolicy,
            $this->retryDelay,
            new PveJsonEnvelopeDecoder(),
            $checkpoint,
        );

        return new PveEndpointReadConnector(
            $transport,
            new PveVersionReader(),
            new PvePermissionReader(),
            new PveClusterStatusReader(),
            new PveClusterResourcesReader(),
            new PveStorageConfigurationReader(),
            new PveNodeStorageStatusReader(),
            new PveTaskPageReader(),
        );
    }
}
