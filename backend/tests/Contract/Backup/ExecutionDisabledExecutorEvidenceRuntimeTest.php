<?php

declare(strict_types=1);

namespace App\Tests\Contract\Backup;

use App\Application\Backup\Execution\BackupExecutionGate;
use App\Application\Backup\Execution\BackupSubmissionTransaction;
use App\Application\Backup\Execution\DefinitiveBackupFailureNotice;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshClaim;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshEndpoint;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailureCode;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshStore;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshSubject;
use App\Application\Backup\Execution\ExecutorPermissionProjection;
use App\Application\Backup\Execution\ExistingSubmissionStatus;
use App\Application\Backup\Execution\ProjectExecutorPermissionEvidence;
use App\Application\Backup\Execution\RefreshExecutorPermissionEvidence;
use App\Application\Backup\Execution\SubmissionPreparation;
use App\Application\Backup\Execution\SubmitClaimedBackup;
use App\Application\Backup\Execution\SubmitClaimedBackupCommand;
use App\Application\Backup\Monitoring\AmbiguousSubmissionEvidence;
use App\Application\Backup\Monitoring\AmbiguousSubmissionIdentity;
use App\Application\Backup\Monitoring\AmbiguousSubmissionReconciliationStore;
use App\Application\Backup\Monitoring\AmbiguousSubmissionTaskSource;
use App\Application\Backup\Monitoring\BackupMonitoringTransaction;
use App\Application\Backup\Monitoring\MonitorClaimedBackup;
use App\Application\Backup\Monitoring\MonitorClaimedBackupCommand;
use App\Application\Backup\Monitoring\PreparedBackupMonitoring;
use App\Application\Backup\Monitoring\PveTaskStatusClassifier;
use App\Application\Backup\Monitoring\ReconcileAmbiguousSubmission;
use App\Application\Backup\Monitoring\ReconcileAmbiguousSubmissionCommand;
use App\Application\Backup\Monitoring\StopAttemptDisposition;
use App\Application\Backup\Queue\BackupQueueStore;
use App\Application\Backup\Queue\ClaimNextBackupCommand;
use App\Application\Backup\Queue\ClaimedBackupRequest;
use App\Application\Backup\Queue\FinalizeClaimedBackupCommand;
use App\Application\Backup\Queue\ShadowPromotion;
use App\Application\Backup\Worker\BackupNotificationDeliveryHook;
use App\Application\Backup\Worker\BackupRunIdentifierSource;
use App\Application\Backup\Worker\BackupWorkerRunner;
use App\Application\Backup\Worker\BackupWorkerTickStatus;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveBackupClient;
use App\Application\Proxmox\Pve\PveBackupClientProvider;
use App\Application\Proxmox\Pve\PveBackupSubmission;
use App\Application\Proxmox\Pve\PveBackupSubmissionResult;
use App\Application\Proxmox\Pve\PveTaskLifecycle;
use App\Application\Proxmox\Pve\PveTaskLogPage;
use App\Application\Proxmox\Pve\PveTaskLogQuery;
use App\Application\Proxmox\Pve\PveTaskPage;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Application\Proxmox\Pve\PveTaskStatus;
use App\Application\Proxmox\Pve\PveTaskStopResult;
use App\Application\Proxmox\Pve\PveTaskStopStatus;
use App\Application\Proxmox\Pve\PveUpid;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\PlaintextSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Domain\Backup\ControlledRetryPolicy;
use App\Domain\Backup\MonitoringOutcome;
use App\Domain\Backup\RecoveryOutcome;
use App\Domain\Shared\Clock;
use App\Infrastructure\Proxmox\ExecutorEvidence\NativePveExecutorEvidenceRefreshSource;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveExecutorEvidenceConfigurationSource;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveExecutorEvidenceEndpointConfiguration;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveExecutorEvidenceHttpClientFactory;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveExecutorPermissionSnapshotParser;
use App\Infrastructure\Proxmox\PveTlsConfiguration;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ExecutionDisabledExecutorEvidenceRuntimeTest extends TestCase
{
    private const string CONNECTION = 'cccccccccccccccc';
    private const string ENDPOINT = 'eeeeeeeeeeeeeeee';
    private const string WORKER = 'wwwwwwwwwwwwwwww';

    public function testExecutionDisabledRefreshesThroughTwoGetsWithoutStartingOrStoppingPveTasks(): void
    {
        $now = new DateTimeImmutable('2026-07-16T10:00:00Z');
        $clock = new ContractClock($now);
        $http = new ContractEvidenceHttpFactory();
        $configuration = new PveExecutorEvidenceEndpointConfiguration(
            self::CONNECTION,
            self::ENDPOINT,
            4,
            5,
            6,
            9,
            'pve-fixture.test',
            8006,
            PveTlsConfiguration::systemCa(),
            'backup@pve!hoddmimir',
            EncryptedSecret::fromEncoded('backup-envelope'),
            SecretContext::forBinaryCredentialId('bbbbbbbbbbbbbbbb', SecretPurpose::PveBackupToken),
            'scan@pve!inventory',
            EncryptedSecret::fromEncoded('scan-envelope'),
            SecretContext::forBinaryCredentialId('ssssssssssssssss', SecretPurpose::PveCollectorToken),
        );
        $source = new NativePveExecutorEvidenceRefreshSource(
            new ContractEvidenceConfigurationSource($configuration),
            $http,
            new ContractSecretCipher(),
            new PveExecutorPermissionSnapshotParser(),
        );
        $evidenceStore = new ContractEvidenceStore($this->evidenceClaim($now));
        $refresh = new RefreshExecutorPermissionEvidence(
            $evidenceStore,
            $source,
            new ProjectExecutorPermissionEvidence(),
            $clock,
        );

        $queue = new ContractQueue($this->runningClaim($now));
        $execution = new ContractExecutionGate(false);
        $pve = new ContractPveClient();
        $monitoring = new ContractMonitoringTransaction($this->upid());
        $notifications = new ContractNotifications();
        $runner = new BackupWorkerRunner(
            $refresh,
            $queue,
            $execution,
            new SubmitClaimedBackup($execution, new ContractSubmissionTransaction(), $pve, new ControlledRetryPolicy(), new \App\Application\Backup\Execution\CheckBackupNodeTasks($pve), $clock),
            new MonitorClaimedBackup($monitoring, $pve, new PveTaskStatusClassifier(), $clock, $execution),
            new ReconcileAmbiguousSubmission(new ContractReconciliationStore(), new ContractReconciliationSource(), $clock),
            new ContractRunIds(),
            $notifications,
            $clock,
        );

        self::assertSame(BackupWorkerTickStatus::Monitored, $runner->runOnce(self::WORKER));
        self::assertSame(['GET', 'GET'], $http->methods);
        self::assertSame([
            '/api2/json/access/permissions',
            '/api2/json/access/acl',
        ], $http->paths);
        self::assertTrue($evidenceStore->published);
        self::assertSame(self::ENDPOINT, $evidenceStore->boundEndpoint);
        self::assertSame(1, $queue->claimCalls);
        self::assertFalse($queue->allowNewClaims);
        self::assertSame(0, $pve->submitCalls, 'execution=false must emit no PVE backup POST');
        self::assertSame(0, $monitoring->stopClaims);
        self::assertSame(0, $pve->stopCalls, 'execution=false must emit no PVE task DELETE');
        self::assertSame(1, $pve->logCalls);
        self::assertSame(1, $pve->statusCalls);
        self::assertSame(1, $notifications->calls);
    }

    private function evidenceClaim(DateTimeImmutable $now): ExecutorEvidenceRefreshClaim
    {
        return new ExecutorEvidenceRefreshClaim(
            self::CONNECTION,
            4,
            5,
            6,
            self::WORKER,
            'llllllllllllllll',
            7,
            $now->modify('+90 seconds'),
            [new ExecutorEvidenceRefreshEndpoint(self::ENDPOINT, 10)],
        );
    }

    private function runningClaim(DateTimeImmutable $now): ClaimedBackupRequest
    {
        return new ClaimedBackupRequest(
            'qqqqqqqqqqqqqqqq',
            'tttttttttttttttt',
            1,
            $now->modify('+2 minutes'),
            'nnnnnnnnnnnnnnnn',
            'dddddddddddddddd',
            '1000',
            'running',
            'rrrrrrrrrrrrrrrr',
        );
    }

    private function upid(): PveUpid
    {
        return PveUpid::parse('UPID:pve-a:0000002A:000F4240:67000000:vzdump:101:backup@pve:');
    }
}

final readonly class ContractClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
}

final readonly class ContractExecutionGate implements BackupExecutionGate
{
    public function __construct(private bool $enabled) {}
    public function enabled(): bool { return $this->enabled; }
}

final readonly class ContractEvidenceConfigurationSource implements PveExecutorEvidenceConfigurationSource
{
    public function __construct(private PveExecutorEvidenceEndpointConfiguration $configuration) {}
    public function load(ExecutorEvidenceRefreshClaim $claim, ExecutorEvidenceRefreshEndpoint $endpoint): PveExecutorEvidenceEndpointConfiguration
    {
        return $this->configuration;
    }
}

final class ContractEvidenceHttpFactory implements PveExecutorEvidenceHttpClientFactory
{
    /** @var list<string> */ public array $methods = [];
    /** @var list<string> */ public array $paths = [];

    public function create(PveTlsConfiguration $tls): HttpClientInterface
    {
        return new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->methods[] = $method;
            $path = (string) parse_url($url, PHP_URL_PATH);
            $this->paths[] = $path;

            return new MockResponse(match ($path) {
                '/api2/json/access/permissions' => '{"data":{"/vms":{"VM.Backup":1},"/storage":{"Datastore.AllocateSpace":1}}}',
                '/api2/json/access/acl' => '{"data":[]}',
                default => throw new \LogicException('Unexpected evidence request path.'),
            });
        });
    }
}

final readonly class ContractSecretCipher implements SecretCipher
{
    public function encrypt(PlaintextSecret $plaintext, SecretContext $context): EncryptedSecret { throw new \LogicException('unused'); }
    public function decrypt(EncryptedSecret $encrypted, SecretContext $context): PlaintextSecret
    {
        return PlaintextSecret::fromString(match ($context->purpose()) {
            SecretPurpose::PveBackupToken => 'backup-secret',
            SecretPurpose::PveCollectorToken => 'scan-secret',
            default => throw new \LogicException('Unexpected evidence secret purpose.'),
        });
    }
    public function primaryKeyId(): string { return 'contract'; }
}

final class ContractEvidenceStore implements ExecutorEvidenceRefreshStore
{
    public bool $published = false;
    public ?string $boundEndpoint = null;
    public function __construct(private ?ExecutorEvidenceRefreshClaim $claim) {}
    public function claimDue(string $workerId, DateTimeImmutable $now): ?ExecutorEvidenceRefreshClaim
    {
        $claim = $this->claim;
        $this->claim = null;
        return $claim;
    }
    public function renew(ExecutorEvidenceRefreshClaim $claim, DateTimeImmutable $now): void {}
    public function bindSnapshotEndpoint(ExecutorEvidenceRefreshClaim $claim, string $endpointId, DateTimeImmutable $observedAt): void
    {
        $this->boundEndpoint = $endpointId;
    }
    /** @return list<ExecutorEvidenceRefreshSubject> */
    public function subjects(ExecutorEvidenceRefreshClaim $claim, ?string $afterSubjectKey, int $limit): array { return []; }
    /** @param list<ExecutorPermissionProjection> $projections */
    public function stage(ExecutorEvidenceRefreshClaim $claim, array $projections): void { throw new \LogicException('No subjects should be staged.'); }
    public function publish(ExecutorEvidenceRefreshClaim $claim, DateTimeImmutable $observedAt): void { $this->published = true; }
    public function fail(ExecutorEvidenceRefreshClaim $claim, ExecutorEvidenceRefreshFailureCode $code, DateTimeImmutable $now): void
    {
        throw new \LogicException('The fixture evidence must publish.');
    }
}

final class ContractQueue implements BackupQueueStore
{
    public int $claimCalls = 0;
    public bool $allowNewClaims = true;
    public function __construct(private ?ClaimedBackupRequest $existing) {}
    public function promote(ShadowPromotion $promotion): string { throw new \LogicException('unused'); }
    public function claim(ClaimNextBackupCommand $command): ?ClaimedBackupRequest
    {
        ++$this->claimCalls;
        $this->allowNewClaims = $command->allowNewClaims;
        return $this->existing;
    }
    public function finalize(FinalizeClaimedBackupCommand $command): bool { throw new \LogicException('unused'); }
}

final class ContractMonitoringTransaction implements BackupMonitoringTransaction
{
    public int $stopClaims = 0;
    public function __construct(private ?PveUpid $upid) {}
    public function renew(MonitorClaimedBackupCommand $command): bool { return true; }
    public function prepare(MonitorClaimedBackupCommand $command): ?PreparedBackupMonitoring
    {
        if (null === $this->upid) return null;
        return new PreparedBackupMonitoring($this->upid, 0, StopAttemptDisposition::ReadyToClaim);
    }
    public function claimStopAttempt(MonitorClaimedBackupCommand $command, PveUpid $upid): bool { ++$this->stopClaims; return true; }
    public function appendLogPage(MonitorClaimedBackupCommand $command, PveUpid $upid, PveTaskLogPage $page): void {}
    public function recordStopAttempt(MonitorClaimedBackupCommand $command, PveUpid $upid, ?PveTaskStopStatus $status, ?PveBackupApiFailureCode $failure): void {}
    public function recordObservation(MonitorClaimedBackupCommand $command, PveUpid $upid, MonitoringOutcome $outcome, ?string $exitStatus, ?PveBackupApiFailureCode $failure): void {}
}

final class ContractPveClient implements PveBackupClient, PveBackupClientProvider
{
    public int $submitCalls = 0;
    public int $stopCalls = 0;
    public int $logCalls = 0;
    public int $statusCalls = 0;
    public function forRequest(string $requestId): PveBackupClient { return $this; }
    public function submit(PveBackupSubmission $submission): PveBackupSubmissionResult { ++$this->submitCalls; return PveBackupSubmissionResult::ambiguous(); }
    public function taskStatus(PveUpid $upid): PveTaskStatus
    {
        ++$this->statusCalls;
        return new PveTaskStatus($upid, PveTaskLifecycle::Running, null, null, []);
    }
    public function taskLog(PveUpid $upid, PveTaskLogQuery $query): PveTaskLogPage
    {
        ++$this->logCalls;
        return new PveTaskLogPage($query, []);
    }
    public function stopTask(PveUpid $upid): PveTaskStopResult { ++$this->stopCalls; return PveTaskStopResult::requested(); }
    public function taskPage(string $node, PveTaskQuery $query): PveTaskPage { return new PveTaskPage($query, 0, [], []); }
}

final readonly class ContractSubmissionTransaction implements BackupSubmissionTransaction
{
    public function inspectExistingSubmission(SubmitClaimedBackupCommand $command): ExistingSubmissionStatus { throw new \LogicException('unused'); }
    public function taskInspectionNodes(SubmitClaimedBackupCommand $command): array { return ['node-a']; }
    public function deferRemoteTaskCheck(SubmitClaimedBackupCommand $command, string $blocker): void {}
    public function prepareAfterFullRevalidation(SubmitClaimedBackupCommand $command): SubmissionPreparation { throw new \LogicException('unused'); }
    public function recordAccepted(SubmitClaimedBackupCommand $command, PveUpid $upid): void { throw new \LogicException('unused'); }
    public function recordDefinitiveRejection(SubmitClaimedBackupCommand $command, PveBackupApiFailureCode $failure, DefinitiveBackupFailureNotice $notice): void { throw new \LogicException('unused'); }
    public function recordAmbiguous(SubmitClaimedBackupCommand $command, ?PveBackupApiFailureCode $failure): void { throw new \LogicException('unused'); }
}

final readonly class ContractReconciliationStore implements AmbiguousSubmissionReconciliationStore
{
    public function renew(ReconcileAmbiguousSubmissionCommand $command): bool { throw new \LogicException('unused'); }
    public function prepare(ReconcileAmbiguousSubmissionCommand $command): ?AmbiguousSubmissionIdentity { throw new \LogicException('unused'); }
    public function record(ReconcileAmbiguousSubmissionCommand $command, RecoveryOutcome $outcome): void { throw new \LogicException('unused'); }
}

final readonly class ContractReconciliationSource implements AmbiguousSubmissionTaskSource
{
    public function read(AmbiguousSubmissionIdentity $identity, callable $beforePage): AmbiguousSubmissionEvidence { throw new \LogicException('unused'); }
}

final readonly class ContractRunIds implements BackupRunIdentifierSource
{
    public function next(): string { throw new \LogicException('unused'); }
}

final class ContractNotifications implements BackupNotificationDeliveryHook
{
    public int $calls = 0;
    public function deliverOne(DateTimeImmutable $now): void { ++$this->calls; }
}
