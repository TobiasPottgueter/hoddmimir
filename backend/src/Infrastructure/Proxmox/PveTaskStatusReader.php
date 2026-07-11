<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Proxmox\Pve\PveBackupInventoryIssue;
use App\Application\Proxmox\Pve\PveBackupInventoryIssueCode;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Application\Proxmox\Pve\PveTaskLifecycle;
use App\Application\Proxmox\Pve\PveTaskStatus;
use App\Application\Proxmox\Pve\PveUpid;
use App\Application\Proxmox\Pve\PveVersion;

final readonly class PveTaskStatusReader
{
    public function __construct(private PveVersion $version)
    {
    }

    public function read(string $routeNode, PveUpid $routeUpid, mixed $data): PveTaskStatus
    {
        $row = $this->row($data);
        if (null === $row || array_is_list($row)) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }

        $issues = [];
        $endpoint = sprintf('/nodes/%s/tasks/{upid}/status', $routeNode);
        $reportedUpid = $this->string($row, 'upid', $endpoint, $routeNode, $issues, false);
        $node = $this->string($row, 'node', $endpoint, $routeNode, $issues, false);
        $type = $this->string($row, 'type', $endpoint, $routeNode, $issues, false);
        $id = $this->identifier($row, $endpoint, $routeNode, $issues);
        $user = $this->string($row, 'user', $endpoint, $routeNode, $issues, false);
        $pid = $this->integer($row, 'pid', $endpoint, $routeNode, $issues, false);
        $startTime = $this->startTime($row, $endpoint, $routeNode, $issues);

        $pstart = null;
        if (array_key_exists('pstart', $row)) {
            $pstart = $this->integer($row, 'pstart', $endpoint, $routeNode, $issues, false);
        } elseif ($this->version->major >= 8) {
            $issues[] = $this->issue(
                PveBackupInventoryIssueCode::MissingRequiredField,
                $endpoint,
                '/data/pstart',
                $routeNode,
                $routeUpid->raw,
            );
        }

        if ($routeNode !== $routeUpid->node || $reportedUpid !== $routeUpid->raw
            || $node !== $routeNode || $type !== $routeUpid->type || $id !== $routeUpid->id
            || $user !== $routeUpid->user || $pid !== $routeUpid->pid
            || $startTime !== $routeUpid->startTime
            || (null !== $pstart && $pstart !== $routeUpid->processStart)) {
            $issues[] = $this->issue(
                PveBackupInventoryIssueCode::IdentityMismatch,
                $endpoint,
                '/data/identity',
                $routeNode,
                $routeUpid->raw,
            );
        }

        $rawLifecycle = $this->string($row, 'status', $endpoint, $routeNode, $issues, false);
        $lifecycle = match ($rawLifecycle) {
            'running' => PveTaskLifecycle::Running,
            'stopped' => PveTaskLifecycle::Stopped,
            default => null,
        };
        if (null !== $rawLifecycle && null === $lifecycle) {
            $issues[] = $this->issue(
                PveBackupInventoryIssueCode::InvalidField,
                $endpoint,
                '/data/status',
                $routeNode,
                $routeUpid->raw,
            );
        }

        $exitStatus = null;
        if (array_key_exists('exitstatus', $row)) {
            $exitStatus = $this->visible($row['exitstatus'], false);
            if (null === $exitStatus) {
                $issues[] = $this->issue(
                    PveBackupInventoryIssueCode::InvalidField,
                    $endpoint,
                    '/data/exitstatus',
                    $routeNode,
                    $routeUpid->raw,
                );
            }
        }

        if ((PveTaskLifecycle::Running === $lifecycle && null !== $exitStatus)
            || (PveTaskLifecycle::Stopped === $lifecycle && null === $exitStatus)) {
            $issues[] = $this->issue(
                PveBackupInventoryIssueCode::InconsistentTaskStatus,
                $endpoint,
                '/data/exitstatus',
                $routeNode,
                $routeUpid->raw,
            );
        }

        return new PveTaskStatus($routeUpid, $lifecycle, $exitStatus, $pstart, $issues);
    }

    /** @return null|array<array-key, mixed> */
    private function row(mixed $value): ?array
    {
        if ($value instanceof \stdClass) {
            return get_object_vars($value);
        }

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed>          $row
     * @param list<PveBackupInventoryIssue> $issues
     */
    private function string(
        array $row,
        string $field,
        string $endpoint,
        string $node,
        array &$issues,
        bool $allowEmpty,
    ): ?string {
        if (!array_key_exists($field, $row)) {
            $issues[] = $this->issue(
                PveBackupInventoryIssueCode::MissingRequiredField,
                $endpoint,
                '/data/'.$field,
                $node,
            );
            return null;
        }
        $value = $this->visible($row[$field], $allowEmpty);
        if (null === $value) {
            $issues[] = $this->issue(
                PveBackupInventoryIssueCode::InvalidField,
                $endpoint,
                '/data/'.$field,
                $node,
            );
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed>          $row
     * @param list<PveBackupInventoryIssue> $issues
     */
    private function identifier(array $row, string $endpoint, string $node, array &$issues): ?string
    {
        if (!array_key_exists('id', $row)) {
            $issues[] = $this->issue(
                PveBackupInventoryIssueCode::MissingRequiredField,
                $endpoint,
                '/data/id',
                $node,
            );
            return null;
        }
        if (is_int($row['id']) && $row['id'] >= 0) {
            return (string) $row['id'];
        }
        $value = $this->visible($row['id'], true);
        if (null === $value) {
            $issues[] = $this->issue(PveBackupInventoryIssueCode::InvalidField, $endpoint, '/data/id', $node);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed>          $row
     * @param list<PveBackupInventoryIssue> $issues
     */
    private function integer(
        array $row,
        string $field,
        string $endpoint,
        string $node,
        array &$issues,
        bool $allowIntegralFloat,
    ): ?int {
        if (!array_key_exists($field, $row)) {
            $issues[] = $this->issue(
                PveBackupInventoryIssueCode::MissingRequiredField,
                $endpoint,
                '/data/'.$field,
                $node,
            );
            return null;
        }
        $value = $this->integral($row[$field], $allowIntegralFloat);
        if (null === $value) {
            $issues[] = $this->issue(
                PveBackupInventoryIssueCode::InvalidField,
                $endpoint,
                '/data/'.$field,
                $node,
            );
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed>          $row
     * @param list<PveBackupInventoryIssue> $issues
     */
    private function startTime(array $row, string $endpoint, string $node, array &$issues): ?int
    {
        return $this->integer(
            $row,
            'starttime',
            $endpoint,
            $node,
            $issues,
            7 === $this->version->major,
        );
    }

    private function integral(mixed $value, bool $allowFloat): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if ($allowFloat && is_float($value) && is_finite($value) && $value >= 0.0
            && floor($value) === $value && $value <= (float) PHP_INT_MAX) {
            return (int) $value;
        }

        return null;
    }

    private function visible(mixed $value, bool $allowEmpty): ?string
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
        PveBackupInventoryIssueCode $code,
        string $endpoint,
        string $field,
        string $node,
        ?string $upid = null,
    ): PveBackupInventoryIssue {
        return new PveBackupInventoryIssue($code, $endpoint, $field, $node, $upid);
    }
}
