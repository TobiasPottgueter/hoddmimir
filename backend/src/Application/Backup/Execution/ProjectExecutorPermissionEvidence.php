<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use InvalidArgumentException;

final readonly class ProjectExecutorPermissionEvidence
{
    public function project(
        PveExecutorPermissionSnapshot $snapshot,
        ExecutorEvidenceRefreshSubject $subject,
    ): ExecutorPermissionProjection {
        if ($snapshot->connectionId !== $subject->connectionId) {
            throw new InvalidArgumentException('Executor permission snapshot and subject connections differ.');
        }
        $matrix = [];
        foreach ($snapshot->backupPermissionMatrix as $entry) {
            $matrix[$entry->path] = $entry->privileges;
        }

        return new ExecutorPermissionProjection(
            $subject,
            $snapshot->endpointId,
            $snapshot->connectionRevision,
            $snapshot->backupCredentialRevision,
            $snapshot->scanCredentialRevision,
            null === $subject->guestId
                ? $this->uniformFamilyGrant($snapshot, $matrix, '/vms', 'VM.Backup')
                : $this->subjectGrant($snapshot, $matrix, $subject->guestPath(), 'VM.Backup'),
            $this->subjectGrant(
                $snapshot,
                $matrix,
                $subject->storagePath(),
                'Datastore.AllocateSpace',
            ),
        );
    }

    /** @param array<string, array<string, 0|1>> $matrix */
    private function subjectGrant(
        PveExecutorPermissionSnapshot $snapshot,
        array $matrix,
        string $subjectPath,
        string $privilege,
    ): bool {
        if (isset($matrix[$subjectPath])) {
            return \array_key_exists($privilege, $matrix[$subjectPath]);
        }

        $sourcePath = $this->nearestMatrixAncestor($matrix, $subjectPath, $privilege);
        if (null === $sourcePath || $this->hasRelevantOverride($snapshot, $sourcePath, $subjectPath)
            || $this->hasRelevantPoolOverride($snapshot)) {
            return false;
        }

        return true;
    }

    /** @param array<string, array<string, 0|1>> $matrix */
    private function uniformFamilyGrant(
        PveExecutorPermissionSnapshot $snapshot,
        array $matrix,
        string $familyPath,
        string $privilege,
    ): bool {
        $family = $matrix[$familyPath] ?? null;
        if (null === $family || 1 !== ($family[$privilege] ?? null)) {
            return false;
        }

        foreach ($matrix as $path => $privileges) {
            if ($path !== $familyPath && $this->isWithin($familyPath, $path)
                && !\array_key_exists($privilege, $privileges)) {
                return false;
            }
        }
        foreach ($snapshot->scanAcl as $acl) {
            if ($this->relevant($snapshot, $acl)
                && (($acl->path !== $familyPath && $this->isWithin($familyPath, $acl->path))
                    || $this->isPoolPath($acl->path))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, array<string, 0|1>> $matrix
     */
    private function nearestMatrixAncestor(array $matrix, string $subjectPath, string $privilege): ?string
    {
        $candidate = $subjectPath;
        for ($remainingAncestors = \substr_count($subjectPath, '/'); $remainingAncestors > 0; --$remainingAncestors) {
            $offset = (int) \strrpos($candidate, '/');
            $candidate = 0 === $offset ? '/' : \substr($candidate, 0, $offset);
            if (!isset($matrix[$candidate])) {
                continue;
            }

            return 1 === ($matrix[$candidate][$privilege] ?? null) ? $candidate : null;
        }

        return null;
    }

    private function hasRelevantOverride(
        PveExecutorPermissionSnapshot $snapshot,
        string $sourcePath,
        string $subjectPath,
    ): bool {
        foreach ($snapshot->scanAcl as $acl) {
            if ($acl->path !== $sourcePath && $this->isWithin($sourcePath, $acl->path)
                && $this->isWithin($acl->path, $subjectPath) && $this->relevant($snapshot, $acl)
                && ($acl->propagate || $acl->path === $subjectPath)) {
                return true;
            }
        }

        return false;
    }

    private function hasRelevantPoolOverride(PveExecutorPermissionSnapshot $snapshot): bool
    {
        foreach ($snapshot->scanAcl as $acl) {
            if ($this->isPoolPath($acl->path) && $this->relevant($snapshot, $acl)) {
                return true;
            }
        }

        return false;
    }

    private function relevant(PveExecutorPermissionSnapshot $snapshot, PveExecutorAclEntry $acl): bool
    {
        return 'group' === $acl->type
            || ('token' === $acl->type && $snapshot->backupTokenIdentity === $acl->identity)
            || ('user' === $acl->type && $snapshot->backupOwnerIdentity === $acl->identity);
    }

    private function isPoolPath(string $path): bool
    {
        return '/pool' === $path || \str_starts_with($path, '/pool/');
    }

    private function isWithin(string $ancestor, string $path): bool
    {
        return '/' === $ancestor || $ancestor === $path || \str_starts_with($path, $ancestor.'/');
    }
}
