<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Proxmox\Pve\PveBackupInventoryIssue;
use App\Application\Proxmox\Pve\PveBackupInventoryIssueCode;
use App\Application\Proxmox\Pve\PveBackupJob;
use App\Application\Proxmox\Pve\PveBackupJobCapabilities;
use App\Application\Proxmox\Pve\PveBackupJobInventory;
use App\Application\Proxmox\Pve\PveBackupJobResponseContract;
use App\Application\Proxmox\Pve\PvePruneBackups;
use App\Application\Proxmox\Pve\PvePruneResponseShape;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Application\Proxmox\Pve\PveVersion;
use App\Application\Proxmox\Pve\PveStorageIdValidator;
use App\Application\Proxmox\Pve\PveTaskNodeNameValidator;

final readonly class PveBackupJobReader
{
    private const ENDPOINT = '/cluster/backup';

    public function __construct(private PveVersion $version)
    {
    }

    public function read(mixed $data): PveBackupJobInventory
    {
        if (!is_array($data) || !array_is_list($data)) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }

        $capabilities = PveBackupJobCapabilities::forMajor($this->version->major);
        $jobs = [];
        $issues = [];
        $ids = [];

        foreach ($data as $index => $rawRow) {
            $row = $this->row($rawRow);
            if (null === $row) {
                $issues[] = $this->issue(PveBackupInventoryIssueCode::InvalidField, $index, 'row');
                continue;
            }

            $id = $this->jobId($row['id'] ?? null);
            if (null === $id) {
                $issues[] = $this->issue(
                    array_key_exists('id', $row)
                        ? PveBackupInventoryIssueCode::InvalidField
                        : PveBackupInventoryIssueCode::MissingRequiredField,
                    $index,
                    'id',
                );
                continue;
            }

            if (isset($ids[$id])) {
                $issues[] = $this->issue(PveBackupInventoryIssueCode::DuplicateJob, $index, 'id', $id);
                continue;
            }
            $ids[$id] = true;

            if (PveBackupJobResponseContract::SelectedTypedFields === $capabilities->responseContract) {
                $this->validateSelectedPve9Fields($row, $index, $id, $issues);
            }

            $legacyMaxFiles = null;
            if (array_key_exists('maxfiles', $row)) {
                if (!$capabilities->supportsLegacyMaxFiles) {
                    $issues[] = $this->issue(
                        PveBackupInventoryIssueCode::UnsupportedCapability,
                        $index,
                        'maxfiles',
                        $id,
                    );
                } elseif (is_int($row['maxfiles']) && $row['maxfiles'] >= 0) {
                    $legacyMaxFiles = $row['maxfiles'];
                } else {
                    $issues[] = $this->issue(PveBackupInventoryIssueCode::InvalidField, $index, 'maxfiles', $id);
                }
            }

            $prune = null;
            if (array_key_exists('prune-backups', $row)) {
                $prune = $this->prune($row['prune-backups'], $capabilities->pruneResponseShape);
                if (null === $prune) {
                    $issues[] = $this->issue(
                        PveBackupInventoryIssueCode::InvalidField,
                        $index,
                        'prune-backups',
                        $id,
                    );
                }
            }

            $jobs[] = new PveBackupJob(
                $id,
                PveBackupJobResponseContract::SelectedTypedFields === $capabilities->responseContract
                    ? $this->boundedString($row['schedule'] ?? null, 128)
                    : $this->stringValue($row['schedule'] ?? null),
                $this->boolValue($row['enabled'] ?? null),
                $this->boolValue($row['repeat-missed'] ?? null),
                PveBackupJobResponseContract::SelectedTypedFields === $capabilities->responseContract
                    ? $this->boundedString($row['comment'] ?? null, 512)
                    : $this->stringValue($row['comment'] ?? null),
                $this->nonNegativeInt($row['next-run'] ?? null),
                PveBackupJobResponseContract::SelectedTypedFields === $capabilities->responseContract
                    ? $this->taskNode($row['node'] ?? null)
                    : $this->stringValue($row['node'] ?? null),
                PveBackupJobResponseContract::SelectedTypedFields === $capabilities->responseContract
                    ? $this->storageId($row['storage'] ?? null)
                    : $this->stringValue($row['storage'] ?? null),
                PveBackupJobResponseContract::SelectedTypedFields === $capabilities->responseContract
                    ? $this->vmidList($row['vmid'] ?? null)
                    : $this->stringValue($row['vmid'] ?? null),
                $this->boolValue($row['all'] ?? null),
                PveBackupJobResponseContract::SelectedTypedFields === $capabilities->responseContract
                    ? $this->allowedStringValue($row['mode'] ?? null, ['snapshot', 'suspend', 'stop'])
                    : $this->stringValue($row['mode'] ?? null),
                PveBackupJobResponseContract::SelectedTypedFields === $capabilities->responseContract
                    ? $this->allowedStringValue($row['compress'] ?? null, ['0', '1', 'gzip', 'lzo', 'zstd'])
                    : $this->stringValue($row['compress'] ?? null),
                $legacyMaxFiles,
                $prune,
            );
        }

        return new PveBackupJobInventory($capabilities, $jobs, $issues);
    }

    /** @return null|array<array-key, mixed> */
    private function row(mixed $row): ?array
    {
        if ($row instanceof \stdClass) {
            return get_object_vars($row);
        }

        return is_array($row) ? $row : null;
    }

    private function jobId(mixed $value): ?string
    {
        if (!is_string($value) || '' === $value || strlen($value) > 50) {
            return null;
        }

        return strlen($value) === strspn(
            $value,
            '!"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~',
        ) ? $value : null;
    }

    /**
     * @param array<array-key, mixed>          $row
     * @param list<PveBackupInventoryIssue> $issues
     */
    private function validateSelectedPve9Fields(array $row, int $index, string $id, array &$issues): void
    {
        if (array_key_exists('vmid', $row) && null === $this->vmidList($row['vmid'])) {
            $issues[] = $this->issue(PveBackupInventoryIssueCode::InvalidField, $index, 'vmid', $id);
        }

        if (array_key_exists('node', $row)
            && (!is_string($row['node']) || !PveTaskNodeNameValidator::isValid($row['node']))) {
            $issues[] = $this->issue(PveBackupInventoryIssueCode::InvalidField, $index, 'node', $id);
        }

        if (array_key_exists('storage', $row)
            && (!is_string($row['storage']) || !PveStorageIdValidator::isValid($row['storage']))) {
            $issues[] = $this->issue(PveBackupInventoryIssueCode::InvalidField, $index, 'storage', $id);
        }

        foreach (['all', 'enabled', 'repeat-missed'] as $field) {
            if (array_key_exists($field, $row) && null === $this->boolValue($row[$field])) {
                $issues[] = $this->issue(PveBackupInventoryIssueCode::InvalidField, $index, $field, $id);
            }
        }

        if (array_key_exists('next-run', $row) && null === $this->nonNegativeInt($row['next-run'])) {
            $issues[] = $this->issue(PveBackupInventoryIssueCode::InvalidField, $index, 'next-run', $id);
        }

        foreach ([
            'schedule' => 128,
            'comment' => 512,
        ] as $field => $maximumLength) {
            if (array_key_exists($field, $row)
                && null === $this->boundedString($row[$field], $maximumLength)) {
                $issues[] = $this->issue(PveBackupInventoryIssueCode::InvalidField, $index, $field, $id);
            }
        }

        foreach ([
            'mode' => ['snapshot', 'suspend', 'stop'],
            'compress' => ['0', '1', 'gzip', 'lzo', 'zstd'],
        ] as $field => $allowed) {
            if (array_key_exists($field, $row) && null === $this->allowedStringValue($row[$field], $allowed)) {
                $issues[] = $this->issue(PveBackupInventoryIssueCode::InvalidField, $index, $field, $id);
            }
        }

    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && '' !== $value && $this->visible($value) ? $value : null;
    }

    private function boundedString(mixed $value, int $maximumLength): ?string
    {
        $string = $this->stringValue($value);
        return null !== $string && strlen($string) <= $maximumLength ? $string : null;
    }

    /** @param list<string> $allowed */
    private function allowedStringValue(mixed $value, array $allowed): ?string
    {
        $string = $this->stringValue($value);
        if (null === $string) {
            return null;
        }
        foreach ($allowed as $candidate) {
            if ($string === $candidate) {
                return $string;
            }
        }

        return null;
    }

    private function boolValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (0 === $value || 1 === $value) {
            return (bool) $value;
        }

        return null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function taskNode(mixed $value): ?string
    {
        return is_string($value) && PveTaskNodeNameValidator::isValid($value) ? $value : null;
    }

    private function storageId(mixed $value): ?string
    {
        return is_string($value) && PveStorageIdValidator::isValid($value) ? $value : null;
    }

    private function vmidList(mixed $value): ?string
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }
        foreach (explode(',', $value) as $vmid) {
            $length = strlen($vmid);
            if ($length < 3 || $length > 9 || $length !== strspn($vmid, '0123456789')) {
                return null;
            }
            $numeric = (int) $vmid;
            if ($numeric < 100) {
                return null;
            }
        }

        return $value;
    }

    private function prune(mixed $value, PvePruneResponseShape $shape): ?PvePruneBackups
    {
        if (is_string($value)) {
            if (PvePruneResponseShape::Object === $shape) {
                return null;
            }
            $parsed = [];
            foreach (explode(',', $value) as $token) {
                $parts = explode('=', $token, 2);
                if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
                    return null;
                }
                $parsed[$parts[0]] = ctype_digit($parts[1]) ? (int) $parts[1] : $parts[1];
            }
            $value = $parsed;
        }

        $row = $this->row($value);
        if (null === $row || array_is_list($row)) {
            return null;
        }

        $keepAll = null;
        if (array_key_exists('keep-all', $row)) {
            $keepAll = $this->boolValue($row['keep-all']);
            if (null === $keepAll) {
                return null;
            }
        }

        $values = [];
        foreach (['keep-last', 'keep-hourly', 'keep-daily', 'keep-weekly', 'keep-monthly', 'keep-yearly'] as $field) {
            if (!array_key_exists($field, $row)) {
                $values[$field] = null;
                continue;
            }
            $parsed = $this->nonNegativeInt($row[$field]);
            if (null === $parsed) {
                return null;
            }
            $values[$field] = $parsed;
        }

        return new PvePruneBackups(
            $keepAll,
            $values['keep-last'],
            $values['keep-hourly'],
            $values['keep-daily'],
            $values['keep-weekly'],
            $values['keep-monthly'],
            $values['keep-yearly'],
        );
    }

    private function visible(string $value): bool
    {
        return strlen($value) === strspn(
            $value,
            ' !"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~',
        );
    }

    private function issue(
        PveBackupInventoryIssueCode $code,
        int $index,
        string $field,
        ?string $id = null,
    ): PveBackupInventoryIssue {
        return new PveBackupInventoryIssue(
            $code,
            self::ENDPOINT,
            sprintf('/data/%d/%s', $index, $field),
            identifier: $id,
        );
    }
}
