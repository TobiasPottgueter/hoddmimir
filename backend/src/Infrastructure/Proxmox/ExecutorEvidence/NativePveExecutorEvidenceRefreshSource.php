<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\ExecutorEvidence;

use App\Application\Backup\Execution\ExecutorEvidenceRefreshClaim;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshEndpoint;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailure;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailureCode;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshSource;
use App\Application\Backup\Execution\PveExecutorPermissionSnapshot;
use App\Application\Security\SecretCipher;
use App\Infrastructure\Proxmox\PveApiUrlBuilder;
use Throwable;
use SensitiveParameter;

final readonly class NativePveExecutorEvidenceRefreshSource implements ExecutorEvidenceRefreshSource
{
    public function __construct(
        private PveExecutorEvidenceConfigurationSource $configurations,
        private PveExecutorEvidenceHttpClientFactory $clients,
        private SecretCipher $cipher,
        private PveExecutorPermissionSnapshotParser $parser,
    ) {
    }

    public function read(
        ExecutorEvidenceRefreshClaim $claim,
        ExecutorEvidenceRefreshEndpoint $endpoint,
    ): PveExecutorPermissionSnapshot {
        try {
            $configuration = $this->configurations->load($claim, $endpoint);
        } catch (ExecutorEvidenceRefreshFailure $safe) {
            throw $safe;
        } catch (Throwable) {
            throw ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::ConfigurationChanged);
        }
        if (!$this->matches($configuration, $claim, $endpoint)) {
            throw ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::ConfigurationChanged);
        }

        try {
            $http = $this->clients->create($configuration->tls);
            $transport = new PveExecutorEvidenceHttpGetTransport($http, new PveApiUrlBuilder($configuration->host, $configuration->port));
            $backupAuthenticator = $configuration->backupAuthenticator($this->cipher);
            $scanAuthenticator = $configuration->scanAuthenticator($this->cipher);
        } catch (ExecutorEvidenceRefreshFailure $safe) {
            throw $safe;
        } catch (Throwable) {
            throw ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::Tls);
        }

        [$matrix, $acl] = $backupAuthenticator->authorize(
            fn (#[SensitiveParameter] string $backupAuthorization): array => $scanAuthenticator->authorize(
                fn (#[SensitiveParameter] string $scanAuthorization): array => [
                    $this->attempt($transport, PveExecutorEvidenceRequest::BackupPermissions, $backupAuthorization),
                    $this->attempt($transport, PveExecutorEvidenceRequest::ScanAcl, $scanAuthorization),
                ],
            ),
        );

        $failure = $this->bundleFailure($matrix['failure'], $acl['failure']);
        if ($failure instanceof ExecutorEvidenceRefreshFailure) {
            throw $failure;
        }

        return $this->parser->parse($configuration, $matrix['data'], $acl['data']);
    }

    /** @return array{data: mixed, failure: ?ExecutorEvidenceRefreshFailure} */
    private function attempt(
        PveExecutorEvidenceGetTransport $transport,
        PveExecutorEvidenceRequest $request,
        string $authorization,
    ): array
    {
        try {
            return ['data' => $transport->get($request, $authorization), 'failure' => null];
        } catch (ExecutorEvidenceRefreshFailure $failure) {
            return ['data' => null, 'failure' => $failure];
        }
    }

    private function bundleFailure(
        ?ExecutorEvidenceRefreshFailure $matrix,
        ?ExecutorEvidenceRefreshFailure $acl,
    ): ?ExecutorEvidenceRefreshFailure {
        foreach ([$acl, $matrix] as $failure) {
            if ($failure instanceof ExecutorEvidenceRefreshFailure
                && \in_array($failure->failureCode, [
                    ExecutorEvidenceRefreshFailureCode::CredentialUnavailable,
                    ExecutorEvidenceRefreshFailureCode::Authentication,
                    ExecutorEvidenceRefreshFailureCode::PermissionDenied,
                    ExecutorEvidenceRefreshFailureCode::ConfigurationChanged,
                ], true)) {
                return $failure;
            }
        }
        return $acl ?? $matrix;
    }

    private function matches(
        PveExecutorEvidenceEndpointConfiguration $configuration,
        ExecutorEvidenceRefreshClaim $claim,
        ExecutorEvidenceRefreshEndpoint $endpoint,
    ): bool {
        return $configuration->connectionId === $claim->connectionId
            && $configuration->endpointId === $endpoint->id
            && $configuration->connectionRevision === $claim->connectionRevision
            && $configuration->backupCredentialRevision === $claim->backupCredentialRevision
            && $configuration->scanCredentialRevision === $claim->scanCredentialRevision;
    }
}
