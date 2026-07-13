<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Configuration\Connection\Onboarding;

use App\Application\Configuration\Connection\Onboarding\OnboardProxmoxConnection;
use App\Application\Configuration\Connection\Onboarding\OnboardingActivationCommand;
use App\Application\Configuration\Connection\Onboarding\OnboardingActivationRepository;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredential;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredentialKind;
use App\Application\Configuration\Connection\Onboarding\OnboardingEndpoint;
use App\Application\Configuration\Connection\Onboarding\OnboardingEvidenceVerifier;
use App\Application\Configuration\Connection\Onboarding\OnboardingIdentityEvidence;
use App\Application\Configuration\Connection\Onboarding\OnboardingIssueCode;
use App\Application\Configuration\Connection\Onboarding\OnboardingMode;
use App\Application\Configuration\Connection\Onboarding\OnboardingMutationResult;
use App\Application\Configuration\Connection\Onboarding\OnboardingMutationStatus;
use App\Application\Configuration\Connection\Onboarding\OnboardingPermission;
use App\Application\Configuration\Connection\Onboarding\OnboardingProduct;
use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteEvidence;
use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteFailure;
use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteGateway;
use App\Application\Configuration\Connection\Onboarding\OnboardingRoleDefinition;
use App\Application\Configuration\Connection\Onboarding\OnboardingTlsMode;
use App\Application\Configuration\Connection\Onboarding\OnboardingVerification;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\AuthorizationDenied;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\UserId;
use PHPUnit\Framework\TestCase;

final class OnboardProxmoxConnectionTest extends TestCase
{
    public function testPassedRemoteEvidenceIsAtomicallyActivated(): void
    {
        $repository = new RecordingOnboardingRepository();
        $service = $this->service(new FixedOnboardingGateway($this->evidence()), $repository);

        $result = $service->execute($this->command(), $this->principal([Permission::BackupConfigurationManage]));

        self::assertSame(OnboardingMutationStatus::Applied, $result->status);
        self::assertSame(1, $repository->activations);
        self::assertSame(0, $repository->records);
        self::assertTrue($repository->verification?->passed());
    }

    public function testRemoteFailureIsSanitizedRecordedAndNeverActivates(): void
    {
        $repository = new RecordingOnboardingRepository();
        $service = $this->service(new FixedOnboardingGateway(new OnboardingRemoteFailure(OnboardingIssueCode::AuthenticationFailed, OnboardingCredentialKind::Scan)), $repository);

        $result = $service->execute($this->command(), $this->principal([Permission::BackupConfigurationManage]));

        self::assertSame(OnboardingMutationStatus::Rejected, $result->status);
        self::assertSame(0, $repository->activations);
        self::assertSame(1, $repository->records);
        $verification = $result->verification ?? self::fail('The rejected result lacks verification evidence.');
        self::assertSame(OnboardingIssueCode::AuthenticationFailed, $verification->issues[0]->code);
        self::assertSame(OnboardingCredentialKind::Scan, $verification->issues[0]->credential);
    }

    public function testInvalidEffectivePermissionsAreRecordedByActivationRepositoryAndNeverApplied(): void
    {
        $repository = new RecordingOnboardingRepository();
        $evidence = $this->evidence();
        $scan = $evidence->identity(OnboardingCredentialKind::Scan) ?? self::fail('missing scan');
        $unsafe = new OnboardingRemoteEvidence(true, [
            new OnboardingIdentityEvidence($scan->kind, $scan->detectedProduct, $scan->major, $scan->minor, $scan->rawVersion, [
                ...$scan->permissions,
                new OnboardingPermission('/vms', 'VM.PowerMgmt', true, true),
            ]),
            $evidence->identity(OnboardingCredentialKind::Backup) ?? self::fail('missing backup'),
        ], $evidence->roles);

        $result = $this->service(new FixedOnboardingGateway($unsafe), $repository)
            ->execute($this->command(), $this->principal([Permission::BackupConfigurationManage]));

        self::assertSame(OnboardingMutationStatus::Rejected, $result->status);
        self::assertSame(1, $repository->activations);
        self::assertSame(0, $repository->applied);
    }

    public function testDeniedPrincipalIsAuditedWithoutRemoteReadAndRethrown(): void
    {
        $repository = new RecordingOnboardingRepository();
        $gateway = new FixedOnboardingGateway($this->evidence());
        $service = $this->service($gateway, $repository);

        try {
            $service->execute($this->command(), $this->principal([]));
            self::fail('A principal without backup_configuration.manage was accepted.');
        } catch (AuthorizationDenied) {
            self::assertSame(0, $gateway->calls);
            self::assertSame(1, $repository->records);
            $lastResult = $repository->lastResult ?? self::fail('The denied result was not recorded.');
            self::assertSame(OnboardingMutationStatus::Denied, $lastResult->status);
            $verification = $lastResult->verification ?? self::fail('The denied result lacks verification evidence.');
            self::assertSame(OnboardingIssueCode::ApplicationPermissionDenied, $verification->issues[0]->code);
        }
    }

    private function service(OnboardingRemoteGateway $gateway, OnboardingActivationRepository $repository): OnboardProxmoxConnection
    {
        return new OnboardProxmoxConnection(new PermissionAuthorizer(), $gateway, new OnboardingEvidenceVerifier(), $repository);
    }

    private function command(): OnboardingActivationCommand
    {
        return new OnboardingActivationCommand(OnboardingMode::Activate, str_repeat('c', 16), 0, 'key', str_repeat('r', 16), OnboardingProduct::Pve, 'PVE',
            new OnboardingEndpoint('pve.example.test', 8006, OnboardingTlsMode::SystemCa, null, null), [
                new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pve!scan', 'scan-secret'),
                new OnboardingCredential(OnboardingCredentialKind::Backup, 'hoddmimir@pve!backup', 'backup-secret'),
            ]);
    }

    private function evidence(): OnboardingRemoteEvidence
    {
        return new OnboardingRemoteEvidence(true, [
            new OnboardingIdentityEvidence(OnboardingCredentialKind::Scan, OnboardingProduct::Pve, 8, 4, '8.4.1', [
                new OnboardingPermission('/', 'Sys.Audit', true, true), new OnboardingPermission('/nodes', 'Sys.Audit', true, true),
                new OnboardingPermission('/vms', 'VM.Audit', true, true), new OnboardingPermission('/pool', 'Pool.Audit', true, true),
                new OnboardingPermission('/storage', 'Datastore.Audit', true, true),
            ]),
            new OnboardingIdentityEvidence(OnboardingCredentialKind::Backup, OnboardingProduct::Pve, 8, 4, '8.4.1', [
                new OnboardingPermission('/vms', 'VM.Backup', true, true), new OnboardingPermission('/vms', 'Datastore.AllocateSpace', true, true),
                new OnboardingPermission('/storage', 'VM.Backup', true, true), new OnboardingPermission('/storage', 'Datastore.AllocateSpace', true, true),
            ]),
        ], [
            new OnboardingRoleDefinition('HoddmimirScan', ['Sys.Audit', 'VM.Audit', 'Pool.Audit', 'Datastore.Audit']),
            new OnboardingRoleDefinition('HoddmimirBackup', ['VM.Backup', 'Datastore.AllocateSpace']),
        ]);
    }

    /** @param list<Permission> $permissions */
    private function principal(array $permissions): AuthenticatedPrincipal
    {
        return new AuthenticatedPrincipal(new UserId(str_repeat('u', 16)), new NormalizedUsername('admin'), $permissions);
    }
}

final class FixedOnboardingGateway implements OnboardingRemoteGateway
{
    public int $calls = 0;
    public function __construct(private readonly OnboardingRemoteEvidence|OnboardingRemoteFailure $result) {}
    public function verify(OnboardingActivationCommand $command): OnboardingRemoteEvidence
    {
        ++$this->calls;
        if ($this->result instanceof OnboardingRemoteFailure) throw $this->result;
        return $this->result;
    }
}

final class RecordingOnboardingRepository implements OnboardingActivationRepository
{
    public int $activations = 0;
    public int $records = 0;
    public int $applied = 0;
    public ?OnboardingVerification $verification = null;
    public ?OnboardingMutationResult $lastResult = null;
    public function activate(OnboardingActivationCommand $command, OnboardingVerification $verification, AuthenticatedPrincipal $principal): OnboardingMutationResult
    {
        ++$this->activations;
        $this->verification = $verification;
        $status = $verification->passed() ? OnboardingMutationStatus::Applied : OnboardingMutationStatus::Rejected;
        if ($verification->passed()) ++$this->applied;
        return $this->lastResult = new OnboardingMutationResult($status, $command->connectionId, $verification->passed() ? 1 : null, $verification);
    }
    public function record(OnboardingActivationCommand $command, OnboardingMutationResult $result, AuthenticatedPrincipal $principal): OnboardingMutationResult
    {
        ++$this->records;
        return $this->lastResult = $result;
    }
}
