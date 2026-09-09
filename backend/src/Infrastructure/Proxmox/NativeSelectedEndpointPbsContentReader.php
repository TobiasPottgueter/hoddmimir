<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\EndpointReadFailure;
use App\Application\Inventory\Connection\EndpointReadFailureCode;
use App\Application\Inventory\PbsContent\PbsContentSnapshot;
use App\Application\Inventory\PbsContent\ReadPbsContent;
use App\Application\Inventory\PbsContent\SelectedEndpointPbsContentReader;
use App\Application\Proxmox\Pbs\PbsContentLimits;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Infrastructure\Proxmox\Pbs\PbsContentClientFactory;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointReadConfigurationSource;
use App\Infrastructure\Proxmox\Pbs\PbsReadConnectorFactoryFailure;

final readonly class NativeSelectedEndpointPbsContentReader implements SelectedEndpointPbsContentReader
{
    public function __construct(
        private PbsEndpointReadConfigurationSource $configurationSource,
        private PbsContentClientFactory $clientFactory,
        private PbsContentLimits $limits,
    ) {}

    /** @param list<PbsDatastoreId> $datastores */
    public function read(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
        array $datastores,
        ConnectionReadCheckpoint $checkpoint,
    ): PbsContentSnapshot {
        $configuration = $this->configurationSource->load($connectionId, $endpointId, $expectedRevision);
        try {
            $client = $this->clientFactory->createContentClient($configuration, $checkpoint);
        } catch (PbsReadConnectorFactoryFailure) {
            throw EndpointReadFailure::for(EndpointReadFailureCode::Tls);
        }
        return (new ReadPbsContent($this->limits))->read($client, $datastores, $checkpoint);
    }
}
