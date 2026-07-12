<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\EndpointInstallationReader;
use App\Application\Inventory\Connection\EndpointReadFailure;
use App\Application\Inventory\Connection\EndpointReadFailureCode;
use App\Application\Inventory\Connection\PbsEndpointReadFailureMapper;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Proxmox\Pbs\PbsDatastoreScanScope;
use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\ReadPbsInstallation;

final readonly class PbsEndpointInstallationReader implements EndpointInstallationReader
{
    public function __construct(
        private PbsEndpointReadConfigurationSource $configurationSource,
        private PbsReadConnectorFactory $connectorFactory,
        private PbsEndpointReadFailureMapper $failureMapper,
        private int $maximumDatastoreFanout,
    ) {
    }

    public function read(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
        ProxmoxProduct $product,
        ConnectionReadCheckpoint $checkpoint,
    ): PbsInstallationSnapshot {
        if (ProxmoxProduct::Pbs !== $product) {
            throw EndpointReadFailure::for(EndpointReadFailureCode::UnsupportedProductOrVersion);
        }

        $configuration = $this->configurationSource->load($connectionId, $endpointId, $expectedRevision);
        try {
            $connector = $this->connectorFactory->create($configuration, $checkpoint);

            return (new ReadPbsInstallation(
                $connector,
                PbsDatastoreScanScope::installationWide(),
                $this->maximumDatastoreFanout,
            ))->read();
        } catch (PbsReadConnectorFactoryFailure) {
            throw EndpointReadFailure::for(EndpointReadFailureCode::Tls);
        } catch (PbsReadFailure $failure) {
            throw $this->failureMapper->map($failure);
        }
    }
}
