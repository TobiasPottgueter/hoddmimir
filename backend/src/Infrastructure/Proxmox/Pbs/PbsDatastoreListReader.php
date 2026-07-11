<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsDatastoreBackendType;
use App\Application\Proxmox\Pbs\PbsDatastoreDefinition;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsMaintenanceMode;
use App\Application\Proxmox\Pbs\PbsMountStatus;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Application\Proxmox\Pbs\PbsVersion;
use InvalidArgumentException;
use ValueError;

final readonly class PbsDatastoreListReader
{
    /** @return list<PbsDatastoreDefinition> */
    public function read(PbsApiEnvelope $envelope, PbsVersion $version): array
    {
        if (!is_array($envelope->data)) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        $definitions = [];
        foreach ($envelope->data as $row) {
            if (!$row instanceof \stdClass) {
                throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
            }
            try {
                $id = new PbsDatastoreId(is_string($row->store ?? null) ? $row->store : '');
                $mount = PbsMountStatus::from(is_string($row->{'mount-status'} ?? null) ? $row->{'mount-status'} : '');
                $backend = 3 === $version->major
                    ? PbsDatastoreBackendType::Filesystem
                    : PbsDatastoreBackendType::from(is_string($row->{'backend-type'} ?? null) ? $row->{'backend-type'} : '');
                $maintenance = $this->maintenance($row->maintenance ?? null, $version);
            } catch (InvalidArgumentException|ValueError) {
                throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
            }
            if (isset($definitions[$id->value])) {
                throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
            }
            $definitions[$id->value] = new PbsDatastoreDefinition($id, $backend, $mount, $maintenance);
        }
        ksort($definitions, SORT_STRING);
        return array_values($definitions);
    }

    private function maintenance(mixed $value, PbsVersion $version): ?PbsMaintenanceMode
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || strlen($value) > 256
            || 1 !== preg_match('/\A(?:type=)?(read-only|offline|delete|unmount|s3-refresh)(?:,[ -~]+)?\z/D', $value, $match)) {
            throw new InvalidArgumentException('Invalid PBS maintenance mode.');
        }
        $mode = PbsMaintenanceMode::from($match[1]);
        if (3 === $version->major && PbsMaintenanceMode::S3Refresh === $mode) {
            throw new InvalidArgumentException('Unsupported PBS maintenance mode.');
        }
        return $mode;
    }
}
