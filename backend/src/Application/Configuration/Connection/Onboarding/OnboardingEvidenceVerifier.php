<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

/**
 * Closed, fail-closed interpretation of the effective permission evidence.
 * Unknown privileges are never silently treated as safe.
 */
final readonly class OnboardingEvidenceVerifier
{
    private const array PVE_SCAN_REQUIRED = [
        ['/', 'Sys.Audit'],
        ['/nodes', 'Sys.Audit'],
        ['/vms', 'VM.Audit'],
        ['/pool', 'Pool.Audit'],
        ['/storage', 'Datastore.Audit'],
    ];
    private const array PVE_SCAN_READ_ONLY = [
        'Sys.Audit', 'VM.Audit', 'Pool.Audit', 'Datastore.Audit', 'Sys.Log',
    ];
    private const array PVE_BACKUP_REQUIRED = [
        ['/nodes', 'Sys.Audit'],
        ['/vms', 'VM.Backup'],
        ['/storage', 'Datastore.AllocateSpace'],
    ];
    private const array PVE_BACKUP_ALLOWED = [
        'VM.Backup', 'Datastore.AllocateSpace',
        'Sys.Audit', 'VM.Audit', 'Pool.Audit', 'Datastore.Audit',
    ];
    private const array PBS_SCAN_REQUIRED = [
        ['/system/status', 'Sys.Audit'],
        ['/system/tasks', 'Sys.Audit'],
        ['/datastore', 'Datastore.Audit'],
        ['/remote', 'Remote.Audit'],
    ];
    private const array PBS_SCAN_ALLOWED = ['Sys.Audit', 'Datastore.Audit', 'Remote.Audit'];
    private const array PVE_ROLES = [
        'HoddmimirScan' => ['Datastore.Audit', 'Pool.Audit', 'Sys.Audit', 'VM.Audit'],
        'HoddmimirBackup' => ['Datastore.AllocateSpace', 'VM.Backup'],
    ];

    public function verify(OnboardingActivationCommand $command, OnboardingRemoteEvidence $evidence): OnboardingVerification
    {
        $issues = [];
        if (!$evidence->tlsVerified) {
            $issues[] = $this->error(OnboardingIssueCode::TlsVerificationFailed);
        }
        $expectedKinds = OnboardingProduct::Pve === $command->product
            ? [OnboardingCredentialKind::Scan, OnboardingCredentialKind::Backup]
            : [OnboardingCredentialKind::Scan];
        $identities = [];
        foreach ($expectedKinds as $kind) {
            $identity = $evidence->identity($kind);
            if (null === $identity) {
                $issues[] = $this->error(OnboardingIssueCode::CredentialMissing, $kind);
                continue;
            }
            $identities[$kind->value] = $identity;
        }
        $this->verifyTokenIdentifiers($command, $issues);

        $scan = $identities[OnboardingCredentialKind::Scan->value] ?? null;
        $detectedProduct = $scan?->detectedProduct;
        $detectedVersion = $scan?->rawVersion;
        $productSupported = null !== $scan;
        foreach ($identities as $identity) {
            if ($identity->detectedProduct !== $command->product) {
                $issues[] = $this->error(OnboardingIssueCode::ProductMismatch, $identity->kind);
                $productSupported = false;
                continue;
            }
            $supported = $this->supportsMajor($identity->detectedProduct, $identity->major);
            if (!$supported) {
                $issues[] = $this->error(OnboardingIssueCode::UnsupportedVersion, $identity->kind);
                $productSupported = false;
            }
            if (null !== $scan && ($identity->major !== $scan->major
                || $identity->minor !== $scan->minor || $identity->rawVersion !== $scan->rawVersion)) {
                $issues[] = $this->error(OnboardingIssueCode::VersionEvidenceMismatch, $identity->kind);
                $productSupported = false;
            }
        }

        $scanPermissions = false;
        $backupPermissions = OnboardingProduct::Pve === $command->product ? false : null;
        if (OnboardingProduct::Pve === $command->product) {
            $this->verifyPveRoles($evidence, $issues);
            if (isset($identities['scan'])) {
                $scanPermissions = $this->permissions(
                    $identities['scan'],
                    self::PVE_SCAN_REQUIRED,
                    self::PVE_SCAN_READ_ONLY,
                    true,
                    $issues,
                );
            }
            if (isset($identities['backup'])) {
                $backupPermissions = $this->permissions(
                    $identities['backup'],
                    self::PVE_BACKUP_REQUIRED,
                    self::PVE_BACKUP_ALLOWED,
                    false,
                    $issues,
                );
            }
        } elseif (isset($identities['scan'])) {
            $scanPermissions = $this->permissions(
                $identities['scan'],
                self::PBS_SCAN_REQUIRED,
                self::PBS_SCAN_ALLOWED,
                false,
                $issues,
            );
        }

        $issues = $this->uniqueIssues($issues);
        return new OnboardingVerification(
            $evidence->tlsVerified,
            $productSupported,
            $scanPermissions && !$this->containsCredentialError($issues, OnboardingCredentialKind::Scan),
            null === $backupPermissions
                ? null
                : ($backupPermissions && !$this->containsCredentialError($issues, OnboardingCredentialKind::Backup)),
            $detectedProduct,
            $detectedVersion,
            $issues,
        );
    }

    /** @param list<OnboardingVerificationIssue> $issues */
    private function verifyTokenIdentifiers(OnboardingActivationCommand $command, array &$issues): void
    {
        $expected = OnboardingProduct::Pve === $command->product
            ? [OnboardingCredentialKind::Scan->value => 'hoddmimir@pve!scan', OnboardingCredentialKind::Backup->value => 'hoddmimir@pve!backup']
            : [OnboardingCredentialKind::Scan->value => 'hoddmimir@pbs!scan'];
        $seen = [];
        foreach ($command->credentials as $credential) {
            if (($expected[$credential->kind->value] ?? null) !== $credential->tokenId) {
                $issues[] = $this->error(OnboardingIssueCode::TokenIdentityInvalid, $credential->kind);
            }
            if (isset($seen[$credential->tokenId])) {
                $issues[] = $this->error(OnboardingIssueCode::TokenIdentitiesNotSeparated, $credential->kind);
            }
            $seen[$credential->tokenId] = true;
        }
    }

    /** @param list<OnboardingVerificationIssue> $issues */
    private function verifyPveRoles(OnboardingRemoteEvidence $evidence, array &$issues): void
    {
        $roles = [];
        $duplicates = [];
        foreach ($evidence->roles as $role) {
            if (isset($roles[$role->name])) {
                $duplicates[$role->name] = true;
            }
            $roles[$role->name] = $role->privileges;
        }
        foreach (self::PVE_ROLES as $name => $expected) {
            $kind = 'HoddmimirScan' === $name ? OnboardingCredentialKind::Scan : OnboardingCredentialKind::Backup;
            if (!isset($roles[$name])) {
                $issues[] = $this->error(OnboardingIssueCode::RoleMissing, $kind);
            } elseif (isset($duplicates[$name]) || $roles[$name] !== $expected) {
                $issues[] = $this->error(OnboardingIssueCode::RoleDefinitionMismatch, $kind);
            }
        }
    }

    /**
     * @param list<array{string, string}>      $required
     * @param list<string>                     $allowed
     * @param list<OnboardingVerificationIssue> $issues
     */
    private function permissions(
        OnboardingIdentityEvidence $identity,
        array $required,
        array $allowed,
        bool $warnForAdditional,
        array &$issues,
    ): bool {
        /** @var array<string, array<string, array{granted: bool, propagated: bool}>> $matrix */
        $matrix = [];
        foreach ($identity->permissions as $permission) {
            $existing = $matrix[$permission->path][$permission->privilege] ?? null;
            $granted = $permission->granted;
            $propagated = $permission->propagated;
            if (null !== $existing) {
                if (!$existing['granted']) {
                    $granted = false;
                }
                if (!$existing['propagated']) {
                    $propagated = false;
                }
            }
            $matrix[$permission->path][$permission->privilege] = [
                'granted' => $granted,
                'propagated' => $propagated,
            ];
            if (!$permission->granted) {
                $issues[] = $this->error(OnboardingIssueCode::NoAccessOverride, $identity->kind, $permission->path, $permission->privilege);
            } elseif (!$this->isAllowed($allowed, $permission->privilege)) {
                $issues[] = $this->error(OnboardingIssueCode::ForbiddenPermissionPresent, $identity->kind, $permission->path, $permission->privilege);
            } elseif ($warnForAdditional && !$this->isRequired($required, $permission->path, $permission->privilege)) {
                $issues[] = new OnboardingVerificationIssue(
                    OnboardingIssueCode::AdditionalReadOnlyPermission,
                    OnboardingIssueSeverity::Warning,
                    $identity->kind,
                    $permission->path,
                    $permission->privilege,
                );
            }
        }
        $passed = true;
        foreach ($required as [$path, $privilege]) {
            $value = $matrix[$path][$privilege] ?? null;
            if (null === $value || !$value['granted']) {
                $issues[] = $this->error(OnboardingIssueCode::RequiredPermissionMissing, $identity->kind, $path, $privilege);
                $passed = false;
            } elseif (!$value['propagated']) {
                $issues[] = $this->error(OnboardingIssueCode::PermissionNotPropagated, $identity->kind, $path, $privilege);
                $passed = false;
            }
        }
        return $passed;
    }

    /** @param list<array{string, string}> $required */
    private function isRequired(array $required, string $path, string $privilege): bool
    {
        foreach ($required as $pair) {
            if ([$path, $privilege] === $pair) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $allowed */
    private function isAllowed(array $allowed, string $privilege): bool
    {
        $allowedSet = array_fill_keys($allowed, true);
        return isset($allowedSet[$privilege]);
    }

    private function supportsMajor(OnboardingProduct $product, int $major): bool
    {
        if (OnboardingProduct::Pve === $product) {
            return $major >= 7 && $major <= 9;
        }
        return $major >= 3 && $major <= 4;
    }

    private function error(
        OnboardingIssueCode $code,
        ?OnboardingCredentialKind $credential = null,
        ?string $path = null,
        ?string $privilege = null,
    ): OnboardingVerificationIssue {
        return new OnboardingVerificationIssue($code, OnboardingIssueSeverity::Error, $credential, $path, $privilege);
    }

    /**
     * @param list<OnboardingVerificationIssue> $issues
     * @return list<OnboardingVerificationIssue>
     */
    private function uniqueIssues(array $issues): array
    {
        $unique = [];
        foreach ($issues as $issue) {
            $key = json_encode($issue->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $unique[$key] = $issue;
        }
        ksort($unique, SORT_STRING);
        return array_values($unique);
    }

    /** @param list<OnboardingVerificationIssue> $issues */
    private function containsCredentialError(array $issues, OnboardingCredentialKind $kind): bool
    {
        foreach ($issues as $issue) {
            if ($issue->credential === $kind && OnboardingIssueSeverity::Error === $issue->severity) {
                return true;
            }
        }
        return false;
    }
}
