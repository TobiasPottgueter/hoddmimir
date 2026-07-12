<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Proxmox\Pbs\PbsReadConnector;
use App\Application\Proxmox\Pbs\PbsContentClient;
use App\Application\Proxmox\Pbs\PbsTasksAndJobsLimits;
use App\Application\Security\SecretCipher;
use InvalidArgumentException;
use RuntimeException;

final readonly class PbsNativeReadConnectorFactory implements PbsReadConnectorFactory, PbsMonitoringClientFactory, PbsContentClientFactory
{
    public function __construct(
        private PbsHttpClientFactory $httpClientFactory,
        private SecretCipher $secretCipher,
        private PbsRetryPolicy $retryPolicy,
        private PbsRetryDelay $retryDelay,
    ) {
    }

    public function create(
        PbsEndpointReadConfiguration $configuration,
        ConnectionReadCheckpoint $checkpoint,
    ): PbsReadConnector {
        $transport = $this->transport($configuration, $checkpoint);

        return new PbsEndpointReadConnector(
            $transport,
            new PbsVersionReader(),
            new PbsPingReader(),
            new PbsNodesReader(),
            new PbsPermissionReader(),
            new PbsNodeStatusReader(),
            new PbsInstanceIdentityReader(),
            new PbsDatastoreConfigurationReader(),
            new PbsDatastoreListReader(),
            new PbsDatastoreStatusReader(),
        );
    }

    public function createMonitoringClient(
        PbsEndpointReadConfiguration $configuration,
        ConnectionReadCheckpoint $checkpoint,
        PbsTasksAndJobsLimits $limits,
    ): PbsMonitoringClient {
        return new PbsHttpTasksAndJobsClient(
            $this->transport($configuration, $checkpoint),
            new PbsPermissionReader(),
            new PbsJobListReader(),
            new PbsTaskPageReader(),
            $limits,
        );
    }

    public function createContentClient(
        PbsEndpointReadConfiguration $configuration,
        ConnectionReadCheckpoint $checkpoint,
    ): PbsContentClient {
        return new PbsHttpContentClient(
            $this->transport($configuration, $checkpoint),
            new PbsPermissionReader(),
            new PbsNamespaceListReader(),
            new PbsSnapshotListReader(),
        );
    }

    private function transport(
        PbsEndpointReadConfiguration $configuration,
        ConnectionReadCheckpoint $checkpoint,
    ): PbsApiTransport {
        try {
            $httpClient = $this->httpClientFactory->create($configuration->tls);
        } catch (InvalidArgumentException|RuntimeException) {
            throw new PbsReadConnectorFactoryFailure();
        }

        return new PbsHttpTransport(
            $httpClient,
            new PbsApiUrlBuilder($configuration->host, $configuration->port),
            $configuration->authenticator($this->secretCipher),
            $this->retryPolicy,
            $this->retryDelay,
            new PbsJsonEnvelopeDecoder(),
            $checkpoint,
        );
    }
}
