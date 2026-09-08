<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Configuration;

use App\Application\Configuration\ConfigurationCommand;
use App\Application\Configuration\ConfigurationCommandResult;
use App\Application\Configuration\ConfigurationCommandStatus;
use App\Application\Configuration\ConfigurationCommandType;
use App\Application\Configuration\Policy\PolicyActivationEvidence;
use App\Application\Configuration\Policy\PolicyActivationEvidenceProvider;
use App\Application\Configuration\Policy\PolicyCommandHandler;
use App\Application\Configuration\Policy\PolicyCommandRepository;
use App\Application\Configuration\Selection\SelectionCommandHandler;
use App\Application\Configuration\Selection\SelectionCommandRepository;
use App\Application\Configuration\Target\TargetCandidateEvidence;
use App\Application\Configuration\Target\TargetCandidateEvidenceProvider;
use App\Application\Configuration\Target\TargetExecutorEvidenceProvider;
use App\Application\Configuration\Target\TargetCommandHandler;
use App\Application\Configuration\Target\TargetCommandRepository;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\AuthorizationDenied;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Domain\Policy\PolicyId;
use App\Domain\Policy\BackupPolicy;
use App\Domain\Policy\BackupMode;
use App\Domain\Policy\Compression;
use App\Domain\Policy\FailureNotificationRecipients;
use App\Domain\Policy\PolicyPriority;
use App\Domain\Policy\PolicyRevision;
use App\Domain\Policy\PolicyThresholds;
use App\Domain\Policy\RetentionPolicy;
use App\Domain\Policy\Schedule;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\UserId;
use App\Domain\Target\AllowedNodes;
use App\Domain\Target\ActivationEvidenceObservation;
use App\Domain\Target\BackupTarget;
use App\Domain\Target\BackupTargetId;
use App\Domain\Target\ConcurrencyPolicy;
use App\Domain\Target\MinimumFreeBytes;
use App\Domain\Target\TargetActivationEvidence;
use App\Domain\Target\TargetRevision;
use App\Domain\Target\TargetStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use App\Domain\Shared\Clock;
use App\Domain\Scheduler\EvidenceFreshnessPolicy;
use DateTimeImmutable;

final class ConfigurationCommandTest extends TestCase
{
    private const string ID = 'iiiiiiiiiiiiiiii';

    public function testEnvelopeHashIsCanonicalAndSelectionIsBounded(): void
    {
        $a = $this->command(ConfigurationCommandType::SelectionUpsert, ['b' => 2, 'a' => 1, 'entries' => [['id' => self::ID]]]);
        $b = $this->command(ConfigurationCommandType::SelectionUpsert, ['entries' => [['id' => self::ID]], 'a' => 1, 'b' => 2]);
        self::assertSame($a->payloadHash, $b->payloadHash);
        self::assertNotSame($a->payloadHash, $this->command(ConfigurationCommandType::SelectionUpsert, ['entries' => [['id' => self::ID]], 'a' => true, 'b' => null])->payloadHash);
        self::assertSame(1, $a->boundedEntries('entries'));
        foreach ([
            fn () => new ConfigurationCommand(ConfigurationCommandType::TargetCreate, 'bad', 0, 'key', self::ID),
            fn () => new ConfigurationCommand(ConfigurationCommandType::TargetCreate, self::ID, -1, 'key', self::ID),
            fn () => new ConfigurationCommand(ConfigurationCommandType::TargetCreate, self::ID, 0, ' bad', self::ID),
            fn () => new ConfigurationCommand(ConfigurationCommandType::TargetCreate, self::ID, 0, '', self::ID),
            fn () => new ConfigurationCommand(ConfigurationCommandType::TargetCreate, self::ID, 0, str_repeat('a', 129), self::ID),
            fn () => new ConfigurationCommand(ConfigurationCommandType::TargetCreate, self::ID, 0, 'key!', self::ID),
            fn () => new ConfigurationCommand(ConfigurationCommandType::TargetCreate, self::ID, 0, '.key', self::ID),
            fn () => new ConfigurationCommand(ConfigurationCommandType::TargetCreate, self::ID, 0, 'key', 'bad'),
            fn () => new ConfigurationCommand(ConfigurationCommandType::TargetCreate, self::ID, 0, 'key', self::ID, ['bad' => 1.5]),
            fn () => $this->command(ConfigurationCommandType::SelectionUpsert)->boundedEntries('entries'),
            fn () => $this->command(ConfigurationCommandType::SelectionUpsert, ['entries' => ['named' => []]])->boundedEntries('entries'),
            fn () => $this->command(ConfigurationCommandType::SelectionUpsert, ['entries' => []])->boundedEntries('entries'),
            fn () => $this->command(ConfigurationCommandType::SelectionUpsert, ['entries' => array_fill(0, 501, [])])->boundedEntries('entries'),
        ] as $invalid) {
            try { $invalid(); self::fail('Invalid command accepted.'); } catch (InvalidArgumentException) { self::addToAssertionCount(1); }
        }
    }

    public function testClosedTypesMapAuditAndValidateResults(): void
    {
        foreach (ConfigurationCommandType::cases() as $type) {
            self::assertNotSame('', $type->auditType()->value);
            self::assertContains($type->subjectType(), ['target', 'policy', 'selection', 'guest_override', 'connection']);
        }
        self::assertSame(['blocked'], ConfigurationCommandResult::blocked('blocked')->blockers);
        self::assertSame(ConfigurationCommandStatus::Denied, ConfigurationCommandResult::denied()->status);
        foreach ([ConfigurationCommandStatus::Applied, ConfigurationCommandStatus::Replayed, ConfigurationCommandStatus::Conflict] as $status) {
            self::assertSame(2, (new ConfigurationCommandResult($status, 2))->revision);
        }
        foreach ([
            static fn () => new ConfigurationCommandResult(ConfigurationCommandStatus::Applied, null),
            static fn () => new ConfigurationCommandResult(ConfigurationCommandStatus::Applied, 1, ['blocked']),
            static fn () => new ConfigurationCommandResult(ConfigurationCommandStatus::Blocked, null),
            static fn () => new ConfigurationCommandResult(ConfigurationCommandStatus::Blocked, 1, ['blocked']),
            static fn () => new ConfigurationCommandResult(ConfigurationCommandStatus::Denied, null),
            static fn () => ConfigurationCommandResult::blocked('INVALID'),
            static fn () => ConfigurationCommandResult::blocked('.invalid'),
            static fn () => ConfigurationCommandResult::blocked('bad!'),
            static fn () => ConfigurationCommandResult::blocked(str_repeat('a', 65)),
        ] as $invalid) {
            try { $invalid(); self::fail('Invalid result accepted.'); } catch (InvalidArgumentException) { self::addToAssertionCount(1); }
        }
    }

    public function testTargetHandlerRequiresPermissionAndFailsClosedOnEvidence(): void
    {
        $repository = new FakeConfigurationRepository();
        $candidate = new FakeTargetCandidateEvidence();
        $executor = new FakeTargetExecutorEvidence();
        $handler = new TargetCommandHandler(new PermissionAuthorizer(), $repository, $candidate, $executor, new ConfigurationClock(), new EvidenceFreshnessPolicy());
        $this->expectAuthorization(fn () => $handler->handle($this->command(ConfigurationCommandType::TargetCreate), $this->principal(false)));
        self::assertSame(ConfigurationCommandStatus::Applied,
            $handler->handle($this->command(ConfigurationCommandType::TargetCreate), $this->principal())->status);
        self::assertSame(ConfigurationCommandStatus::Applied,
            $handler->handle($this->command(ConfigurationCommandType::TargetUpdate), $this->principal())->status);
        self::assertSame(ConfigurationCommandStatus::Applied,
            $handler->handle($this->command(ConfigurationCommandType::TargetDisable), $this->principal())->status);
        $repository->target = null;
        self::assertSame(['target_missing'], $handler->handle($this->command(ConfigurationCommandType::TargetEnable), $this->principal())->blockers);
        $repository->target = $this->target();
        $candidate->accepted = null;
        $executor->accepted = null;
        self::assertContains('executor_evidence_missing', $handler->handle($this->command(ConfigurationCommandType::TargetEnable), $this->principal())->blockers);
        $candidate->accepted = true;
        $executor->accepted = true;
        self::assertSame(ConfigurationCommandStatus::Applied, $handler->handle($this->command(ConfigurationCommandType::TargetEnable), $this->principal())->status);
        $this->expectException(InvalidArgumentException::class);
        $handler->handle($this->command(ConfigurationCommandType::PolicyCreate), $this->principal());
    }

    public function testPolicyHandlerChecksPveTargetAndExecutorEvidence(): void
    {
        $repository = new FakeConfigurationRepository();
        $evidence = new FakePolicyEvidence();
        $handler = new PolicyCommandHandler(new PermissionAuthorizer(), $repository, $evidence, new ConfigurationClock(), new EvidenceFreshnessPolicy(), new \App\Application\Configuration\Policy\PolicyActivationAssessor());
        $this->expectAuthorization(fn () => $handler->handle($this->command(ConfigurationCommandType::PolicyCreate), $this->principal(false)));
        $savedPolicy = $repository->policy;
        $repository->policy = null;
        self::assertSame(['policy_missing'], $handler->handle($this->command(ConfigurationCommandType::PolicyEnable), $this->principal())->blockers);
        $repository->policy = $savedPolicy;
        $evidence->value = new PolicyActivationEvidence(null, null, $this->observation(null), $this->observation(null));
        self::assertSame(['pve_evidence_missing', 'target_evidence_missing', 'executor_evidence_missing'],
            $handler->handle($this->command(ConfigurationCommandType::PolicyEnable), $this->principal())->blockers);
        $evidence->value = new PolicyActivationEvidence(10, new DateTimeImmutable('2026-07-12T12:00:00Z'), $this->observation(false), $this->observation(false));
        self::assertSame(['unsupported_pve_major', 'target_disabled', 'executor_unauthorized'],
            $handler->handle($this->command(ConfigurationCommandType::PolicyEnable), $this->principal())->blockers);
        $evidence->value = new PolicyActivationEvidence(9, new DateTimeImmutable('2026-07-12T12:00:00Z'), $this->observation(true), $this->observation(true));
        self::assertSame(ConfigurationCommandStatus::Applied, $handler->handle($this->command(ConfigurationCommandType::PolicyEnable), $this->principal())->status);
        $evidence->value = new PolicyActivationEvidence(
            9,
            new DateTimeImmutable('2026-07-12T12:00:00Z'),
            $this->observation(true),
            $this->observation(true),
            true,
            true,
        );
        self::assertSame(
            ['retention_execution_forbidden_for_pbs_target'],
            $handler->handle($this->command(ConfigurationCommandType::PolicyEnable), $this->principal())->blockers,
        );
        $evidence->value = new PolicyActivationEvidence(9, new DateTimeImmutable('2026-07-12T12:00:00Z'), $this->observation(true), $this->observation(true));
        self::assertSame(ConfigurationCommandStatus::Applied, $handler->handle($this->command(ConfigurationCommandType::PolicyUpdate), $this->principal())->status);
        foreach ([
            new PolicyActivationEvidence(9, new DateTimeImmutable('2026-07-12T11:54:59Z'), $this->observation(true), $this->observation(true)),
            new PolicyActivationEvidence(9, new DateTimeImmutable('2026-07-12T12:00:01Z'), $this->observation(true), $this->observation(true)),
            new PolicyActivationEvidence(9, new DateTimeImmutable('2026-07-12T12:00:00Z'), new ActivationEvidenceObservation(true, new DateTimeImmutable('2026-07-12T11:54:59Z')), new ActivationEvidenceObservation(true, new DateTimeImmutable('2026-07-12T12:00:01Z'))),
        ] as $staleOrFuture) {
            $evidence->value = $staleOrFuture;
            self::assertSame(ConfigurationCommandStatus::Blocked, $handler->handle($this->command(ConfigurationCommandType::PolicyEnable), $this->principal())->status);
        }
        $this->expectException(InvalidArgumentException::class);
        $handler->handle($this->command(ConfigurationCommandType::TargetCreate), $this->principal());
    }

    public function testSelectionHandlerRequiresPermissionTypeAndBounds(): void
    {
        $repository = new FakeConfigurationRepository();
        $handler = new SelectionCommandHandler(new PermissionAuthorizer(), $repository);
        $command = $this->command(ConfigurationCommandType::SelectionUpsert, ['entries' => [['id' => self::ID]]]);
        $this->expectAuthorization(fn () => $handler->handle($command, $this->principal(false)));
        self::assertSame(ConfigurationCommandStatus::Applied, $handler->handle($command, $this->principal())->status);
        foreach ([ConfigurationCommandType::SelectionDisable, ConfigurationCommandType::GuestOverrideUpsert, ConfigurationCommandType::GuestOverrideDisable] as $type) {
            self::assertSame(ConfigurationCommandStatus::Applied,
                $handler->handle($this->command($type, ['entries' => [['id' => self::ID]]]), $this->principal())->status);
        }
        $this->expectException(InvalidArgumentException::class);
        $handler->handle($this->command(ConfigurationCommandType::TargetCreate), $this->principal());
    }

    public function testConfigurationHandlersUseSharedFreshnessOverrideAtMicrosecondBoundary(): void
    {
        $repository = new FakeConfigurationRepository();
        $repository->target = $this->target();
        $candidate = new FakeTargetCandidateEvidence();
        $executor = new FakeTargetExecutorEvidence();
        $candidate->observedAt = new DateTimeImmutable('2026-07-12T11:58:00.000000Z');
        $executor->observedAt = $candidate->observedAt;
        $target = new TargetCommandHandler(new PermissionAuthorizer(), $repository, $candidate, $executor, new ConfigurationClock(), new EvidenceFreshnessPolicy(120));
        self::assertSame(ConfigurationCommandStatus::Applied, $target->handle($this->command(ConfigurationCommandType::TargetEnable), $this->principal())->status);
        $candidate->observedAt = new DateTimeImmutable('2026-07-12T11:57:59.999999Z');
        self::assertSame(ConfigurationCommandStatus::Blocked, $target->handle($this->command(ConfigurationCommandType::TargetEnable), $this->principal())->status);

        $evidence = new FakePolicyEvidence();
        $fresh = new ActivationEvidenceObservation(true, new DateTimeImmutable('2026-07-12T11:58:00.000000Z'));
        $evidence->value = new PolicyActivationEvidence(9, $fresh->observedAt, $fresh, $fresh);
        $policy = new PolicyCommandHandler(new PermissionAuthorizer(), $repository, $evidence, new ConfigurationClock(), new EvidenceFreshnessPolicy(120), new \App\Application\Configuration\Policy\PolicyActivationAssessor());
        self::assertSame(ConfigurationCommandStatus::Applied, $policy->handle($this->command(ConfigurationCommandType::PolicyEnable), $this->principal())->status);
        $stale = new ActivationEvidenceObservation(true, new DateTimeImmutable('2026-07-12T11:57:59.999999Z'));
        $evidence->value = new PolicyActivationEvidence(9, $stale->observedAt, $stale, $stale);
        self::assertSame(ConfigurationCommandStatus::Blocked, $policy->handle($this->command(ConfigurationCommandType::PolicyEnable), $this->principal())->status);
    }

    /** @param array<string, mixed> $payload */
    private function command(ConfigurationCommandType $type, array $payload = []): ConfigurationCommand
    {
        return new ConfigurationCommand($type, self::ID, 1, 'request-key', self::ID, $payload);
    }

    private function principal(bool $allowed = true): AuthenticatedPrincipal
    {
        return new AuthenticatedPrincipal(new UserId(str_repeat('u', 16)), new NormalizedUsername('admin'),
            $allowed ? [Permission::BackupConfigurationManage] : []);
    }

    private function expectAuthorization(callable $operation): void
    {
        try { $operation(); self::fail('Unauthorized command accepted.'); } catch (AuthorizationDenied) { self::addToAssertionCount(1); }
    }

    private function target(): BackupTarget
    {
        return new BackupTarget(new BackupTargetId(self::ID), new TargetRevision(1), TargetStatus::Disabled, false,
            new MinimumFreeBytes('1'), new AllowedNodes([str_repeat('n', 16)]), new ConcurrencyPolicy(1), null);
    }

    private function observation(?bool $accepted): ActivationEvidenceObservation
    {
        return new ActivationEvidenceObservation($accepted, null === $accepted ? null : new DateTimeImmutable('2026-07-12T12:00:00Z'));
    }
}

final class FakeConfigurationRepository implements TargetCommandRepository, PolicyCommandRepository, SelectionCommandRepository
{
    public ?BackupTarget $target = null;
    public ?BackupPolicy $policy;
    public function __construct()
    {
        $id = new PolicyId(str_repeat('i', 16));
        $this->policy = BackupPolicy::draft($id, new PolicyRevision(1), new BackupTargetId(str_repeat('t', 16)),
            BackupMode::Snapshot, Compression::Zstd, RetentionPolicy::prune(null, 1, null, null, null, null, null),
            new PolicyPriority(1), new PolicyThresholds(60, null, null), Schedule::CollectorCycle,
            new FailureNotificationRecipients(['ops@example.test']));
    }
    public function find(BackupTargetId $id): ?BackupTarget { return $this->target; }
    public function findPolicy(PolicyId $id): ?BackupPolicy
    {
        return $this->policy;
    }
    public function execute(ConfigurationCommand $command, AuthenticatedPrincipal $principal): ConfigurationCommandResult
    { return new ConfigurationCommandResult(ConfigurationCommandStatus::Applied, $command->expectedRevision + 1); }
    public function record(ConfigurationCommand $command, AuthenticatedPrincipal $principal, ConfigurationCommandResult $result): ConfigurationCommandResult
    { return $result; }
}

final class FakeTargetCandidateEvidence implements TargetCandidateEvidenceProvider
{
    public ?bool $accepted = true;
    public DateTimeImmutable $observedAt;
    public function __construct() { $this->observedAt = new DateTimeImmutable('2026-07-12T12:00:00Z'); }
    public function candidateEvidence(BackupTargetId $id): TargetCandidateEvidence
    {
        $value = new ActivationEvidenceObservation($this->accepted, null === $this->accepted ? null : $this->observedAt);
        return new TargetCandidateEvidence($value, $value, $value);
    }
    public function candidateEvidenceBatch(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) $result[$id->toHex()] = $this->candidateEvidence($id);
        return $result;
    }
}

final class FakeTargetExecutorEvidence implements TargetExecutorEvidenceProvider
{
    public ?bool $accepted = true;
    public DateTimeImmutable $observedAt;
    public function __construct() { $this->observedAt = new DateTimeImmutable('2026-07-12T12:00:00Z'); }
    public function executorEvidence(BackupTargetId $id): ActivationEvidenceObservation
    { return new ActivationEvidenceObservation($this->accepted, null === $this->accepted ? null : $this->observedAt); }
    public function executorEvidenceBatch(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) $result[$id->toHex()] = $this->executorEvidence($id);
        return $result;
    }
}

final class FakePolicyEvidence implements PolicyActivationEvidenceProvider
{
    public PolicyActivationEvidence $value;
    public function __construct()
    {
        $fresh = new ActivationEvidenceObservation(true, new DateTimeImmutable('2026-07-12T12:00:00Z'));
        $this->value = new PolicyActivationEvidence(9, new DateTimeImmutable('2026-07-12T12:00:00Z'), $fresh, $fresh);
    }
    public function policyEvidence(PolicyId $id): PolicyActivationEvidence { return $this->value; }
    public function policyEvidenceBatch(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) $result[bin2hex($id->binary())] = $this->value;
        return $result;
    }
}

final class ConfigurationClock implements Clock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-07-12T12:00:00Z'); }
}
