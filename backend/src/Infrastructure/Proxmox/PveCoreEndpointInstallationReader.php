<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\EndpointInstallationReader;
use App\Application\Inventory\Connection\EndpointReadFailure;
use App\Application\Inventory\Connection\EndpointReadFailureCode;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Inventory\Connection\PveEndpointReadFailureMapper;
use App\Application\Proxmox\Pve\PveInstallationSnapshot;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\ReadPveInstallation;

/**
 * Productive GET-only PVE core reader. It deliberately has no PBS fallback and
 * is wired only into the read-only collector runtime.
 */
final readonly class PveCoreEndpointInstallationReader implements EndpointInstallationReader
{
    public function __construct(
        private PveEndpointReadConfigurationSource $configurationSource,
        private PveCoreReadConnectorFactory $connectorFactory,
        private PveEndpointReadFailureMapper $failureMapper,
    ) {
    }

    public function read(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
        ProxmoxProduct $product,
        ConnectionReadCheckpoint $checkpoint,
    ): PveInstallationSnapshot {
        if (ProxmoxProduct::Pve !== $product) {
            throw EndpointReadFailure::for(EndpointReadFailureCode::UnsupportedProductOrVersion);
        }

        // No remote object exists before the expected connection revision and
        // all endpoint/credential/TLS metadata have been validated together.
        $configuration = $this->configurationSource->load(
            $connectionId,
            $endpointId,
            $expectedRevision,
        );

        try {
            $connector = $this->connectorFactory->create($configuration, $checkpoint);

            return (new ReadPveInstallation($connector))->read();
        } catch (PveCoreReadConnectorFactoryFailure) {
            throw EndpointReadFailure::for(EndpointReadFailureCode::Tls);
        } catch (PveReadFailure $failure) {
            throw $this->failureMapper->map($failure);
        }
    }
}
