<?php

declare(strict_types=1);

namespace App\Application\Configuration\Target;

use App\Application\Configuration\ConfigurationCommand;
use App\Application\Configuration\ConfigurationCommandResult;
use App\Application\Configuration\ConfigurationCommandType;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Domain\Security\Permission;
use App\Domain\Scheduler\EvidenceFreshnessPolicy;
use App\Domain\Shared\Clock;
use App\Domain\Target\BackupTargetId;
use App\Domain\Target\TargetActivationEvidence;
use App\Application\Security\Auth\AuthorizationDenied;

final readonly class TargetCommandHandler
{
    public function __construct(
        private PermissionAuthorizer $authorizer,
        private TargetCommandRepository $repository,
        private TargetCandidateEvidenceProvider $candidateEvidence,
        private TargetExecutorEvidenceProvider $executorEvidence,
        private Clock $clock,
        private EvidenceFreshnessPolicy $freshness,
    ) {
    }

    public function handle(ConfigurationCommand $command, AuthenticatedPrincipal $principal): ConfigurationCommandResult
    {
        $targetCommand = match ($command->type) {
            ConfigurationCommandType::TargetCreate, ConfigurationCommandType::TargetUpdate,
            ConfigurationCommandType::TargetEnable, ConfigurationCommandType::TargetDisable => true,
            default => false,
        };
        if (!$targetCommand) {
            throw new \InvalidArgumentException('A target handler received an unrelated command.');
        }
        try {
            $this->authorizer->require($principal, Permission::BackupConfigurationManage);
        } catch (AuthorizationDenied $denied) {
            $this->repository->record($command, $principal, ConfigurationCommandResult::denied());
            throw $denied;
        }
        if (ConfigurationCommandType::TargetEnable === $command->type) {
            $target = $this->repository->find(new BackupTargetId($command->subjectId));
            if (null === $target) {
                return $this->repository->record($command, $principal, ConfigurationCommandResult::blocked('target_missing'));
            }
            $candidate = $this->candidateEvidence->candidateEvidence($target->id);
            $assessment = $target->assessActivation(new TargetActivationEvidence(
                $candidate->candidate, $candidate->inventory, $candidate->capacity,
                $this->executorEvidence->executorEvidence($target->id),
            ), $this->clock->now(), $this->freshness->maximumAgeSeconds);
            if (!$assessment->canEnable()) {
                return $this->repository->record($command, $principal, ConfigurationCommandResult::blocked(...array_map(static fn ($blocker): string => $blocker->value, $assessment->blockers)));
            }
        }
        return $this->repository->execute($command, $principal);
    }
}
