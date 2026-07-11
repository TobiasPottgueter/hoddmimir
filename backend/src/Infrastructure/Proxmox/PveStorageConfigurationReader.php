<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Proxmox\Pve\PvePbsStorageMapping;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Application\Proxmox\Pve\PveStorageConfiguration;
use App\Application\Proxmox\Pve\PveStorageConfigurationSet;
use App\Application\Proxmox\Pve\PveStorageContentSet;
use App\Application\Proxmox\Pve\PveStorageIssue;
use App\Application\Proxmox\Pve\PveStorageIssueCode;
use App\Infrastructure\Validation\AsciiCsvTokenSetParser;
use App\Infrastructure\Validation\AsciiPatternValidator;

final readonly class PveStorageConfigurationReader
{
    private const ENDPOINT = '/storage';
    private const VISIBLE_ASCII_PATTERN = '/\A[\x21-\x7E]+\z/D';
    private const STORAGE_ID_PATTERN = '/\A[a-z][a-z0-9._-]*[a-z0-9]\z/iD';
    private const STORAGE_TYPE_PATTERN = '/\A[A-Za-z][A-Za-z0-9._-]*\z/D';
    private const DATASTORE_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D';

    public function read(mixed $data): PveStorageConfigurationSet
    {
        if (!is_array($data) || !array_is_list($data)) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }

        $definitions = [];
        $issues = [];
        $digests = [];
        $allRowsHaveDigest = true;
        $storageIds = [];

        if ([] === $data) {
            $issues[] = $this->issue(PveStorageIssueCode::MissingConfigurationDigest, '/data');
        }

        foreach ($data as $index => $rawRow) {
            $row = $this->row($rawRow);
            if (null === $row) {
                $issues[] = $this->issue(PveStorageIssueCode::InvalidField, sprintf('/data/%d', $index));
                $allRowsHaveDigest = false;
                continue;
            }

            $digest = $this->normalizedString($row['digest'] ?? null, self::VISIBLE_ASCII_PATTERN);
            if (null === $digest) {
                $issues[] = $this->issue(
                    PveStorageIssueCode::MissingConfigurationDigest,
                    sprintf('/data/%d/digest', $index),
                );
                $allRowsHaveDigest = false;
            } else {
                $digests[$digest] = true;
            }

            $definition = $this->definition($row, $index, $issues);
            if (null === $definition) {
                continue;
            }

            if (isset($storageIds[$definition->storageId])) {
                $issues[] = $this->issue(
                    PveStorageIssueCode::DuplicateStorage,
                    sprintf('/data/%d/storage', $index),
                    $definition->storageId,
                );
                continue;
            }

            $storageIds[$definition->storageId] = true;
            $definitions[] = $definition;
        }

        $globalDigest = null;
        if ($allRowsHaveDigest && 1 === count($digests)) {
            $globalDigest = array_key_first($digests);
        } elseif (count($digests) > 1) {
            $issues[] = $this->issue(PveStorageIssueCode::InconsistentConfigurationDigest, '/data/*/digest');
        }

        return new PveStorageConfigurationSet($globalDigest, $definitions, $issues);
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<PveStorageIssue>   $issues
     */
    private function definition(array $row, int $index, array &$issues): ?PveStorageConfiguration
    {
        $storageId = $this->requiredString(
            $row,
            'storage',
            self::STORAGE_ID_PATTERN,
            $index,
            $issues,
        );
        $storageType = $this->requiredString(
            $row,
            'type',
            self::STORAGE_TYPE_PATTERN,
            $index,
            $issues,
            $storageId,
        );
        $content = $this->content($row, $index, $issues, $storageId);
        $nodes = $this->nodes($row, $index, $issues, $storageId);
        $disabled = $this->optionalBool($row, 'disable', $index, $issues, $storageId);
        $shared = $this->optionalBool($row, 'shared', $index, $issues, $storageId);

        if (null === $storageId || null === $storageType || null === $content
            || false === $nodes['valid'] || null === $disabled || null === $shared) {
            return null;
        }

        $pbsMapping = 'pbs' === $storageType
            ? $this->pbsMapping($row, $index, $issues, $storageId)
            : null;

        return new PveStorageConfiguration(
            $storageId,
            $storageType,
            $content,
            $nodes['value'],
            $disabled,
            $shared,
            $pbsMapping,
        );
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
        array $row,
        string $field,
        string $pattern,
        int $index,
        array &$issues,
        ?string $storageId = null,
    ): ?string {
        if (!array_key_exists($field, $row)) {
            $issues[] = $this->issue(
                PveStorageIssueCode::MissingRequiredField,
                sprintf('/data/%d/%s', $index, $field),
                $storageId,
            );
            return null;
        }

        $value = $this->normalizedString($row[$field], $pattern);
        if (null === $value) {
            $issues[] = $this->issue(
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
    private function content(array $row, int $index, array &$issues, ?string $storageId): ?PveStorageContentSet
    {
        if (!array_key_exists('content', $row)) {
            $issues[] = $this->issue(
                PveStorageIssueCode::MissingRequiredField,
                sprintf('/data/%d/content', $index),
                $storageId,
            );
            return null;
        }

        if (!is_string($row['content']) || '' === $row['content']) {
            $issues[] = $this->issue(
                PveStorageIssueCode::InvalidField,
                sprintf('/data/%d/content', $index),
                $storageId,
            );
            return null;
        }

        $tokens = AsciiCsvTokenSetParser::parse($row['content']);
        if (null === $tokens) {
            $issues[] = $this->issue(
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
     *
     * @return array{valid: bool, value: null|list<string>}
     */
    private function nodes(array $row, int $index, array &$issues, ?string $storageId): array
    {
        if (!array_key_exists('nodes', $row) || null === $row['nodes']) {
            return ['valid' => true, 'value' => null];
        }

        if (!is_string($row['nodes'])) {
            $issues[] = $this->issue(
                PveStorageIssueCode::InvalidField,
                sprintf('/data/%d/nodes', $index),
                $storageId,
            );
            return ['valid' => false, 'value' => null];
        }

        $nodes = AsciiCsvTokenSetParser::parse($row['nodes']);
        if (null === $nodes) {
            $issues[] = $this->issue(
                PveStorageIssueCode::InvalidField,
                sprintf('/data/%d/nodes', $index),
                $storageId,
            );
            return ['valid' => false, 'value' => null];
        }

        return ['valid' => true, 'value' => $nodes];
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<PveStorageIssue>   $issues
     */
    private function optionalBool(
        array $row,
        string $field,
        int $index,
        array &$issues,
        ?string $storageId,
    ): ?bool {
        if (!array_key_exists($field, $row)) {
            return false;
        }

        $value = $row[$field];
        if (is_bool($value)) {
            return $value;
        }

        if (0 === $value || 1 === $value) {
            return (bool) $value;
        }

        $issues[] = $this->issue(
            PveStorageIssueCode::InvalidField,
            sprintf('/data/%d/%s', $index, $field),
            $storageId,
        );
        return null;
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<PveStorageIssue>   $issues
     */
    private function pbsMapping(
        array $row,
        int $index,
        array &$issues,
        string $storageId,
    ): ?PvePbsStorageMapping {
        $server = $this->requiredString(
            $row,
            'server',
            self::VISIBLE_ASCII_PATTERN,
            $index,
            $issues,
            $storageId,
        );
        $datastore = $this->requiredString(
            $row,
            'datastore',
            self::DATASTORE_PATTERN,
            $index,
            $issues,
            $storageId,
        );
        $port = 8007;
        if (array_key_exists('port', $row)) {
            if (!is_int($row['port']) || $row['port'] < 1 || $row['port'] > 65535) {
                $issues[] = $this->issue(
                    PveStorageIssueCode::InvalidField,
                    sprintf('/data/%d/port', $index),
                    $storageId,
                );
                $port = null;
            } else {
                $port = $row['port'];
            }
        }

        $namespace = null;
        if (array_key_exists('namespace', $row) && null !== $row['namespace']) {
            $namespace = $this->normalizedString($row['namespace'], self::VISIBLE_ASCII_PATTERN);
            if (null === $namespace) {
                $issues[] = $this->issue(
                    PveStorageIssueCode::InvalidField,
                    sprintf('/data/%d/namespace', $index),
                    $storageId,
                );
            }
        }

        if (null === $server || null === $datastore || null === $port
            || (array_key_exists('namespace', $row) && null !== $row['namespace'] && null === $namespace)) {
            return null;
        }

        return new PvePbsStorageMapping($server, $port, $datastore, $namespace);
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
        PveStorageIssueCode $code,
        string $field,
        ?string $storageId = null,
    ): PveStorageIssue {
        return new PveStorageIssue($code, self::ENDPOINT, $field, $storageId);
    }
}
