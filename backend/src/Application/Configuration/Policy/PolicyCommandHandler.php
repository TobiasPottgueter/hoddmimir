<?php

declare(strict_types=1);

namespace App\Application\Configuration\Policy;

use App\Application\Configuration\ConfigurationCommand;
use App\Application\Configuration\ConfigurationCommandResult;
use App\Application\Configuration\ConfigurationCommandType;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Application\Security\Auth\AuthorizationDenied;
use App\Domain\Policy\PolicyId;
use App\Domain\Scheduler\EvidenceFreshnessPolicy;
use App\Domain\Security\Permission;
use App\Domain\Shared\Clock;
use App\Domain\Target\EvidenceObservationFreshness;
use InvalidArgumentException;

final readonly class PolicyCommandHandler
{
    public function __construct(private PermissionAuthorizer $authorizer, private PolicyCommandRepository $repository, private PolicyActivationEvidenceProvider $evidence, private Clock $clock, private EvidenceFreshnessPolicy $freshness)
    {
    }

    public function handle(ConfigurationCommand $command, AuthenticatedPrincipal $principal): ConfigurationCommandResult
    {
        $policyCommand = match ($command->type) {
            ConfigurationCommandType::PolicyCreate, ConfigurationCommandType::PolicyUpdate,
            ConfigurationCommandType::PolicyEnable, ConfigurationCommandType::PolicyDisable => true,
            default => false,
        };
        if (!$policyCommand) {
            throw new InvalidArgumentException('A policy handler received an unrelated command.');
        }
        try {
            $this->authorizer->require($principal, Permission::BackupConfigurationManage);
        } catch (AuthorizationDenied $denied) {
            $this->repository->record($command, $principal, ConfigurationCommandResult::denied());
            throw $denied;
        }
        if (ConfigurationCommandType::PolicyEnable === $command->type) {
            $id = new PolicyId($command->subjectId);
            $policy = $this->repository->findPolicy($id);
            if (null === $policy) {
                return $this->repository->record($command, $principal, ConfigurationCommandResult::blocked('policy_missing'));
            }
            $evidence = $this->evidence->policyEvidence($id);
            $now = $this->clock->now();
            $blockers = [];
            if (null === $evidence->pveMajor || null === $evidence->pveObservedAt) {
                $blockers[] = 'pve_evidence_missing';
            } else {
                $pveFreshness = (new \App\Domain\Target\ActivationEvidenceObservation(true, $evidence->pveObservedAt))->freshness($now, $this->freshness->maximumAgeSeconds);
                if (EvidenceObservationFreshness::Future === $pveFreshness) {
                    $blockers[] = 'pve_evidence_future';
                } elseif (EvidenceObservationFreshness::Stale === $pveFreshness) {
                    $blockers[] = 'pve_evidence_stale';
                } elseif ($evidence->pveMajor < 7 || $evidence->pveMajor > 9) {
                    $blockers[] = 'unsupported_pve_major';
                }
            }
            array_push($blockers, ...$this->observationBlockers($evidence->target, $now, 'target'));
            array_push($blockers, ...$this->observationBlockers($evidence->executor, $now, 'executor'));
            if (null !== $evidence->pveMajor) {
                array_push($blockers, ...array_map(static fn ($blocker): string => $blocker->value, $policy->activationBlockers($evidence->pveMajor)));
            }
            $blockers = array_values(array_unique($blockers));
            if ([] !== $blockers) {
                return $this->repository->record($command, $principal, ConfigurationCommandResult::blocked(...$blockers));
            }
        }
        return $this->repository->execute($command, $principal);
    }

    /** @return list<string> */
    private function observationBlockers(\App\Domain\Target\ActivationEvidenceObservation $observation, \DateTimeImmutable $now, string $scope): array
    {
        $freshness = $observation->freshness($now, $this->freshness->maximumAgeSeconds);
        if (EvidenceObservationFreshness::Missing === $freshness) return [$scope.'_evidence_missing'];
        if (EvidenceObservationFreshness::Stale === $freshness) return [$scope.'_evidence_stale'];
        if (EvidenceObservationFreshness::Future === $freshness) return [$scope.'_evidence_future'];
        if (true === $observation->accepted) return [];
        return ['target' === $scope ? 'target_disabled' : 'executor_unauthorized'];
    }
}
