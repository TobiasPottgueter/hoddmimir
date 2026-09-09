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
use InvalidArgumentException;

final readonly class PolicyCommandHandler
{
    public function __construct(private PermissionAuthorizer $authorizer, private PolicyCommandRepository $repository, private PolicyActivationEvidenceProvider $evidence, private Clock $clock, private EvidenceFreshnessPolicy $freshness, private PolicyActivationAssessor $assessor)
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
            $assessment = $this->assessor->assess($policy, $this->evidence->policyEvidence($id),
                $this->clock->now(), $this->freshness->maximumAgeSeconds);
            if (!$assessment->canEnable()) {
                return $this->repository->record($command, $principal, ConfigurationCommandResult::blocked(
                    ...array_map(static fn (PolicyActivationBlockerCode $blocker): string => $blocker->value, $assessment->blockers),
                ));
            }
        }
        return $this->repository->execute($command, $principal);
    }

}
