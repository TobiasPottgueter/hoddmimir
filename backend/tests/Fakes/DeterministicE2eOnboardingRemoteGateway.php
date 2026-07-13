<?php

declare(strict_types=1);

namespace App\Tests\Fakes;

use App\Application\Configuration\Connection\Onboarding\OnboardingActivationCommand;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredentialKind;
use App\Application\Configuration\Connection\Onboarding\OnboardingIdentityEvidence;
use App\Application\Configuration\Connection\Onboarding\OnboardingIssueCode;
use App\Application\Configuration\Connection\Onboarding\OnboardingPermission;
use App\Application\Configuration\Connection\Onboarding\OnboardingProduct;
use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteEvidence;
use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteFailure;
use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteGateway;
use App\Application\Configuration\Connection\Onboarding\OnboardingRoleDefinition;

/**
 * Deterministic remote boundary for the isolated APP_ENV=e2e browser stack.
 * It is never wired in dev, test or production and performs no network I/O.
 */
final readonly class DeterministicE2eOnboardingRemoteGateway implements OnboardingRemoteGateway
{
    private const string PVE_SCAN_SECRET = 'qa-pve-scan-secret';
    private const string PVE_BACKUP_SECRET = 'qa-pve-backup-secret';
    private const string PBS_SCAN_SECRET = '00000000-0000-0000-0000-000000000000';

    public function verify(OnboardingActivationCommand $command): OnboardingRemoteEvidence
    {
        if ('pve-fingerprint-failure.qa.invalid' === $command->endpoint->host) {
            throw new OnboardingRemoteFailure(OnboardingIssueCode::TlsFingerprintMismatch);
        }
        if (OnboardingProduct::Pve === $command->product) {
            $this->authenticate($command, OnboardingCredentialKind::Scan, self::PVE_SCAN_SECRET);
            $this->authenticate($command, OnboardingCredentialKind::Backup, self::PVE_BACKUP_SECRET);
            return $this->pve($command->endpoint->host);
        }
        $this->authenticate($command, OnboardingCredentialKind::Scan, self::PBS_SCAN_SECRET);
        return $this->pbs();
    }

    private function authenticate(OnboardingActivationCommand $command, OnboardingCredentialKind $kind, string $expected): void
    {
        $valid = $command->credential($kind)->secret->consume(
            static fn (string $secret): bool => hash_equals($expected, $secret),
        );
        if (!$valid || str_contains($command->endpoint->host, 'auth-failure')) {
            throw new OnboardingRemoteFailure(OnboardingIssueCode::AuthenticationFailed, $kind);
        }
    }

    private function pve(string $host): OnboardingRemoteEvidence
    {
        $scan = [
            new OnboardingPermission('/', 'Sys.Audit', true, true),
            new OnboardingPermission('/nodes', 'Sys.Audit', true, true),
            new OnboardingPermission('/vms', 'VM.Audit', true, true),
            new OnboardingPermission('/pool', 'Pool.Audit', true, true),
            new OnboardingPermission('/storage', 'Datastore.Audit', true, true),
        ];
        if ('pve-missing-permission.qa.invalid' === $host) {
            array_pop($scan);
        } elseif ('pve-forbidden-permission.qa.invalid' === $host) {
            $scan[] = new OnboardingPermission('/vms', 'VM.PowerMgmt', true, true);
        } elseif ('pve-readonly-warning.qa.invalid' === $host) {
            $scan[] = new OnboardingPermission('/nodes', 'Sys.Log', true, true);
        }
        return new OnboardingRemoteEvidence(true, [
            new OnboardingIdentityEvidence(OnboardingCredentialKind::Scan, OnboardingProduct::Pve, 8, 4, '8.4.1', $scan),
            new OnboardingIdentityEvidence(OnboardingCredentialKind::Backup, OnboardingProduct::Pve, 8, 4, '8.4.1', [
                new OnboardingPermission('/vms', 'VM.Backup', true, true),
                new OnboardingPermission('/vms', 'Datastore.AllocateSpace', true, true),
                new OnboardingPermission('/storage', 'VM.Backup', true, true),
                new OnboardingPermission('/storage', 'Datastore.AllocateSpace', true, true),
            ]),
        ], [
            new OnboardingRoleDefinition('HoddmimirScan', ['Sys.Audit', 'VM.Audit', 'Pool.Audit', 'Datastore.Audit']),
            new OnboardingRoleDefinition('HoddmimirBackup', ['VM.Backup', 'Datastore.AllocateSpace']),
        ]);
    }

    private function pbs(): OnboardingRemoteEvidence
    {
        return new OnboardingRemoteEvidence(true, [new OnboardingIdentityEvidence(
            OnboardingCredentialKind::Scan,
            OnboardingProduct::Pbs,
            4,
            0,
            '4.0.2',
            [
                new OnboardingPermission('/system/status', 'Sys.Audit', true, true),
                new OnboardingPermission('/system/tasks', 'Sys.Audit', true, true),
                new OnboardingPermission('/datastore', 'Datastore.Audit', true, true),
                new OnboardingPermission('/remote', 'Remote.Audit', true, true),
            ],
        )]);
    }
}
