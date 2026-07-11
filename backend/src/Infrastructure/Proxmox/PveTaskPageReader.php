<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Proxmox\Pve\PveBackupInventoryIssue;
use App\Application\Proxmox\Pve\PveBackupInventoryIssueCode;
use App\Application\Proxmox\Pve\PveBackupTask;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Application\Proxmox\Pve\PveTaskPage;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Application\Proxmox\Pve\PveUpid;
use InvalidArgumentException;

final readonly class PveTaskPageReader
{
    public function read(string $routeNode, PveTaskQuery $query, mixed $data): PveTaskPage
    {
        if (!is_array($data) || !array_is_list($data)) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }

        $tasks = [];
        $issues = [];
        foreach ($data as $index => $rawRow) {
            $row = $this->row($rawRow);
            if (null === $row) {
                $issues[] = $this->issue($routeNode, $index, 'row', PveBackupInventoryIssueCode::InvalidField);
                continue;
            }

            $upid = $this->upid($row['upid'] ?? null);
            $node = $this->requiredString($row, 'node', $routeNode, $index, $issues);
            $pid = $this->requiredInt($row, 'pid', $routeNode, $index, $issues);
            $pstart = $this->requiredInt($row, 'pstart', $routeNode, $index, $issues);
            $startTime = $this->requiredInt($row, 'starttime', $routeNode, $index, $issues);
            $type = $this->requiredString($row, 'type', $routeNode, $index, $issues);
            $id = $this->requiredIdentifier($row, $routeNode, $index, $issues);
            $user = $this->requiredString($row, 'user', $routeNode, $index, $issues);

            if (null === $upid) {
                $issues[] = $this->issue(
                    $routeNode,
                    $index,
                    'upid',
                    array_key_exists('upid', $row)
                        ? PveBackupInventoryIssueCode::InvalidField
                        : PveBackupInventoryIssueCode::MissingRequiredField,
                );
            }

            if (null === $upid || null === $node || null === $pid || null === $pstart
                || null === $startTime || null === $type || null === $id || null === $user) {
                continue;
            }

            if ($routeNode !== $node || $routeNode !== $upid->node
                || $pid !== $upid->pid || $pstart !== $upid->processStart
                || $startTime !== $upid->startTime || $type !== $upid->type
                || $id !== $upid->id || $user !== $upid->user) {
                $issues[] = $this->issue(
                    $routeNode,
                    $index,
                    'identity',
                    PveBackupInventoryIssueCode::IdentityMismatch,
                    $upid->raw,
                );
                continue;
            }

            $endTime = null;
            if (array_key_exists('endtime', $row)) {
                $endTime = $this->nonNegativeInt($row['endtime']);
                if (null === $endTime) {
                    $issues[] = $this->issue(
                        $routeNode,
                        $index,
                        'endtime',
                        PveBackupInventoryIssueCode::InvalidField,
                        $upid->raw,
                    );
                }
            }

            $status = null;
            if (array_key_exists('status', $row)) {
                $status = $this->visibleString($row['status'], false);
                if (null === $status) {
                    $issues[] = $this->issue(
                        $routeNode,
                        $index,
                        'status',
                        PveBackupInventoryIssueCode::InvalidField,
                        $upid->raw,
                    );
                }
            }

            $tasks[] = new PveBackupTask($upid, $query->source, $endTime, $status);
        }

        return new PveTaskPage($query, count($data), $tasks, $issues);
    }

    /** @return null|array<array-key, mixed> */
    private function row(mixed $value): ?array
    {
        if ($value instanceof \stdClass) {
            return get_object_vars($value);
        }

        return is_array($value) ? $value : null;
    }

    private function upid(mixed $value): ?PveUpid
    {
        if (!is_string($value)) {
            return null;
        }
        try {
            return PveUpid::parse($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<array-key, mixed>          $row
     * @param list<PveBackupInventoryIssue> $issues
     */
    private function requiredString(
        array $row,
        string $field,
        string $node,
        int $index,
        array &$issues,
    ): ?string {
        if (!array_key_exists($field, $row)) {
            $issues[] = $this->issue($node, $index, $field, PveBackupInventoryIssueCode::MissingRequiredField);
            return null;
        }
        $value = $this->visibleString($row[$field], false);
        if (null === $value) {
            $issues[] = $this->issue($node, $index, $field, PveBackupInventoryIssueCode::InvalidField);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed>          $row
     * @param list<PveBackupInventoryIssue> $issues
     */
    private function requiredInt(
        array $row,
        string $field,
        string $node,
        int $index,
        array &$issues,
    ): ?int {
        if (!array_key_exists($field, $row)) {
            $issues[] = $this->issue($node, $index, $field, PveBackupInventoryIssueCode::MissingRequiredField);
            return null;
        }
        $value = $this->nonNegativeInt($row[$field]);
        if (null === $value) {
            $issues[] = $this->issue($node, $index, $field, PveBackupInventoryIssueCode::InvalidField);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed>          $row
     * @param list<PveBackupInventoryIssue> $issues
     */
    private function requiredIdentifier(
        array $row,
        string $node,
        int $index,
        array &$issues,
    ): ?string {
        if (!array_key_exists('id', $row)) {
            $issues[] = $this->issue($node, $index, 'id', PveBackupInventoryIssueCode::MissingRequiredField);
            return null;
        }
        $value = $row['id'];
        if (is_int($value) && $value >= 0) {
            return (string) $value;
        }
        if (is_string($value) && null !== $this->visibleString($value, true)) {
            return $value;
        }
        $issues[] = $this->issue($node, $index, 'id', PveBackupInventoryIssueCode::InvalidField);

        return null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function visibleString(mixed $value, bool $allowEmpty): ?string
    {
        if (!is_string($value) || (!$allowEmpty && '' === $value)) {
            return null;
        }
        if ('' === $value) {
            return $value;
        }

        return strlen($value) === strspn(
            $value,
            ' !"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~',
        ) ? $value : null;
    }

    private function issue(
        string $node,
        int $index,
        string $field,
        PveBackupInventoryIssueCode $code,
        ?string $upid = null,
    ): PveBackupInventoryIssue {
        return new PveBackupInventoryIssue(
            $code,
            sprintf('/nodes/%s/tasks', $node),
            sprintf('/data/%d/%s', $index, $field),
            $node,
            $upid,
        );
    }
}
