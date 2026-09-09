<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Proxmox\Pve\PveNodeStorageStatus;
use App\Application\Proxmox\Pve\PveNodeStorageStatusSet;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Application\Proxmox\Pve\PveStorageCapacity;
use App\Application\Proxmox\Pve\PveStorageCapacityState;
use App\Application\Proxmox\Pve\PveStorageContentSet;
use App\Application\Proxmox\Pve\PveStorageIssue;
use App\Application\Proxmox\Pve\PveStorageIssueCode;
use App\Infrastructure\Validation\AsciiCsvTokenSetParser;
use App\Infrastructure\Validation\AsciiPatternValidator;

final readonly class PveNodeStorageStatusReader
{
    private const NODE_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D';
    private const STORAGE_ID_PATTERN = '/\A[a-z][a-z0-9._-]*[a-z0-9]\z/iD';
    private const STORAGE_TYPE_PATTERN = '/\A[A-Za-z][A-Za-z0-9._-]*\z/D';

    public function read(string $node, mixed $data): PveNodeStorageStatusSet
    {
        if (!AsciiPatternValidator::matches(self::NODE_PATTERN, $node)) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }

        if (!is_array($data) || !array_is_list($data)) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }

        $statuses = [];
        $issues = [];
        $storageIds = [];

        foreach ($data as $index => $rawRow) {
            $row = $this->row($rawRow);
            if (null === $row) {
                $issues[] = $this->issue(
                    $node,
                    PveStorageIssueCode::InvalidField,
                    sprintf('/data/%d', $index),
                );
                continue;
            }

            $storageId = $this->requiredString(
                $node,
                $row,
                'storage',
                self::STORAGE_ID_PATTERN,
                $index,
                $issues,
            );
            $storageType = $this->requiredString(
                $node,
                $row,
                'type',
                self::STORAGE_TYPE_PATTERN,
                $index,
                $issues,
                $storageId,
            );
            $content = $this->content($node, $row, $index, $issues, $storageId);
            $enabled = $this->requiredBool($node, $row, 'enabled', $index, $issues, $storageId);
            $active = $this->requiredBool($node, $row, 'active', $index, $issues, $storageId);
            $shared = $this->requiredBool($node, $row, 'shared', $index, $issues, $storageId);

            if (null === $storageId || null === $storageType || null === $content
                || null === $enabled || null === $active || null === $shared) {
                continue;
            }

            if (isset($storageIds[$storageId])) {
                $issues[] = $this->issue(
                    $node,
                    PveStorageIssueCode::DuplicateStorage,
                    sprintf('/data/%d/storage', $index),
                    $storageId,
                );
                continue;
            }
            $storageIds[$storageId] = true;

            if (!$enabled) {
                $issues[] = $this->issue(
                    $node,
                    PveStorageIssueCode::ConfigurationStatusConflict,
                    sprintf('/data/%d/enabled', $index),
                    $storageId,
                );
            }

            [$capacityState, $capacity] = $this->capacity(
                $node,
                $row,
                $index,
                $storageId,
                $enabled,
                $active,
                $issues,
            );

            $statuses[] = new PveNodeStorageStatus(
                $node,
                $storageId,
                $storageType,
                $content,
                $enabled,
                $active,
                $shared,
                $capacityState,
                $capacity,
            );
        }

        return new PveNodeStorageStatusSet($node, $statuses, $issues);
    }

    /** @return null|array<array-key, mixed> */
    private function row(mixed $row): ?array
    {
        if ($row instanceof \stdClass) {
            return get_object_vars($row);
        }

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<PveStorageIssue>   $issues
     */
    private function requiredString(
        string $node,
        array $row,
        string $field,
        string $pattern,
        int $index,
        array &$issues,
        ?string $storageId = null,
    ): ?string {
        if (!array_key_exists($field, $row)) {
            $issues[] = $this->issue(
                $node,
                PveStorageIssueCode::MissingRequiredField,
                sprintf('/data/%d/%s', $index, $field),
                $storageId,
            );
            return null;
        }

        $value = $this->normalizedString($row[$field], $pattern);
        if (null === $value) {
            $issues[] = $this->issue(
                $node,
                PveStorageIssueCode::InvalidField,
                sprintf('/data/%d/%s', $index, $field),
                $storageId,
            );
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<PveStorageIssue>   $issues
     */
    private function content(
        string $node,
        array $row,
        int $index,
        array &$issues,
        ?string $storageId,
    ): ?PveStorageContentSet {
        if (!array_key_exists('content', $row)) {
            $issues[] = $this->issue(
                $node,
                PveStorageIssueCode::MissingRequiredField,
                sprintf('/data/%d/content', $index),
                $storageId,
            );
            return null;
        }

        if (!is_string($row['content']) || '' === $row['content']) {
            $issues[] = $this->issue(
                $node,
                PveStorageIssueCode::InvalidField,
                sprintf('/data/%d/content', $index),
                $storageId,
            );
            return null;
        }

        $tokens = AsciiCsvTokenSetParser::parse($row['content']);
        if (null === $tokens) {
            $issues[] = $this->issue(
                $node,
                PveStorageIssueCode::InvalidField,
                sprintf('/data/%d/content', $index),
                $storageId,
            );
            return null;
        }

        return new PveStorageContentSet($tokens);
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<PveStorageIssue>   $issues
     */
    private function requiredBool(
        string $node,
        array $row,
        string $field,
        int $index,
        array &$issues,
        ?string $storageId,
    ): ?bool {
        if (!array_key_exists($field, $row)) {
            $issues[] = $this->issue(
                $node,
                PveStorageIssueCode::MissingRequiredField,
                sprintf('/data/%d/%s', $index, $field),
                $storageId,
            );
            return null;
        }

        $value = $row[$field];
        if (is_bool($value)) {
            return $value;
        }

        if (0 === $value || 1 === $value) {
            return (bool) $value;
        }

        $issues[] = $this->issue(
            $node,
            PveStorageIssueCode::InvalidField,
            sprintf('/data/%d/%s', $index, $field),
            $storageId,
        );
        return null;
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<PveStorageIssue>   $issues
     *
     * @return array{PveStorageCapacityState, ?PveStorageCapacity}
     */
    private function capacity(
        string $node,
        array $row,
        int $index,
        string $storageId,
        bool $enabled,
        bool $active,
        array &$issues,
    ): array {
        if (!$enabled || !$active) {
            $capacityValues = [$row['total'] ?? null, $row['used'] ?? null, $row['avail'] ?? null];
            $allMissing = [null, null, null] === $capacityValues;
            $allZero = [0, 0, 0] === $capacityValues;
            if (!$allMissing && !$allZero) {
                $issues[] = $this->issue(
                    $node,
                    PveStorageIssueCode::InvalidCapacity,
                    sprintf('/data/%d/capacity', $index),
                    $storageId,
                );
            }

            return [PveStorageCapacityState::Unavailable, null];
        }

        $total = $row['total'] ?? null;
        $used = $row['used'] ?? null;
        $available = $row['avail'] ?? null;
        if (!is_int($total) || $total < 0
            || !is_int($used) || $used < 0
            || !is_int($available) || $available < 0
            || $used > $total || $available > $total) {
            $issues[] = $this->issue(
                $node,
                PveStorageIssueCode::InvalidCapacity,
                sprintf('/data/%d/capacity', $index),
                $storageId,
            );
            return [PveStorageCapacityState::Invalid, null];
        }

        return [PveStorageCapacityState::Fresh, new PveStorageCapacity($total, $used, $available)];
    }

    private function normalizedString(mixed $value, string $pattern): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $isNormalized = AsciiPatternValidator::matches($pattern, $value);
        return $isNormalized ? $value : null;
    }

    private function issue(
        string $node,
        PveStorageIssueCode $code,
        string $field,
        ?string $storageId = null,
    ): PveStorageIssue {
        return new PveStorageIssue(
            $code,
            sprintf('/nodes/%s/storage', $node),
            $field,
            $storageId,
            $node,
        );
    }
}
