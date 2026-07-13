<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\AuthorizationDenied;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Domain\Security\Permission;

final readonly class OnboardProxmoxConnection
{
    public function __construct(
        private PermissionAuthorizer $authorizer,
        private OnboardingRemoteGateway $remote,
        private OnboardingEvidenceVerifier $verifier,
        private OnboardingActivationRepository $repository,
    ) {
    }

    public function execute(OnboardingActivationCommand $command, AuthenticatedPrincipal $principal): OnboardingMutationResult
    {
        try {
            $this->authorizer->require($principal, Permission::BackupConfigurationManage);
        } catch (AuthorizationDenied $denied) {
            $verification = $this->failed(OnboardingIssueCode::ApplicationPermissionDenied);
            $this->repository->record(
                $command,
                new OnboardingMutationResult(OnboardingMutationStatus::Denied, $command->connectionId, null, $verification),
                $principal,
            );
            throw $denied;
        }

        try {
            $evidence = $this->remote->verify($command);
        } catch (OnboardingRemoteFailure $failure) {
            $verification = $this->failed($failure->failureCode, $failure->credential);
            return $this->repository->record(
                $command,
                new OnboardingMutationResult(OnboardingMutationStatus::Rejected, $command->connectionId, null, $verification),
                $principal,
            );
        }

        return $this->repository->activate($command, $this->verifier->verify($command, $evidence), $principal);
    }

    private function failed(OnboardingIssueCode $code, ?OnboardingCredentialKind $credential = null): OnboardingVerification
    {
        return new OnboardingVerification(
            false,
            false,
            false,
            null,
            null,
            null,
            [new OnboardingVerificationIssue($code, OnboardingIssueSeverity::Error, $credential)],
        );
    }
}
