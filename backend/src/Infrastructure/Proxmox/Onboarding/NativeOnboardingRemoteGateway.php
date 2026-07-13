<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Onboarding;

use App\Application\Configuration\Connection\Onboarding\OnboardingActivationCommand;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredential;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredentialKind;
use App\Application\Configuration\Connection\Onboarding\OnboardingIdentityEvidence;
use App\Application\Configuration\Connection\Onboarding\OnboardingPermission;
use App\Application\Configuration\Connection\Onboarding\OnboardingProduct;
use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteEvidence;
use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteFailure;
use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteGateway;
use App\Application\Configuration\Connection\Onboarding\OnboardingIssueCode;
use App\Application\Configuration\Connection\Onboarding\OnboardingRoleDefinition;
use App\Application\Configuration\Connection\Onboarding\OnboardingTlsMode;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Infrastructure\Proxmox\Pbs\PbsApiEnvelope;
use App\Infrastructure\Proxmox\Pbs\PbsApiTokenIdentity;
use App\Infrastructure\Proxmox\Pbs\PbsApiUrlBuilder;
use App\Infrastructure\Proxmox\Pbs\PbsCertificateFingerprint;
use App\Infrastructure\Proxmox\Pbs\PbsCustomCaCertificate;
use App\Infrastructure\Proxmox\Pbs\PbsHttpClientFactory;
use App\Infrastructure\Proxmox\Pbs\PbsHttpTransport;
use App\Infrastructure\Proxmox\Pbs\PbsJsonEnvelopeDecoder;
use App\Infrastructure\Proxmox\Pbs\PbsPermissionReader;
use App\Infrastructure\Proxmox\Pbs\PbsRequest;
use App\Infrastructure\Proxmox\Pbs\PbsRetryDelay;
use App\Infrastructure\Proxmox\Pbs\PbsRetryPolicy;
use App\Infrastructure\Proxmox\Pbs\PbsTlsConfiguration;
use App\Infrastructure\Proxmox\Pbs\PbsVersionReader;
use App\Infrastructure\Proxmox\PveApiTokenIdentity;
use App\Infrastructure\Proxmox\PveApiUrlBuilder;
use App\Infrastructure\Proxmox\PveCertificateFingerprint;
use App\Infrastructure\Proxmox\PveCustomCaCertificate;
use App\Infrastructure\Proxmox\PveHttpClientFactory;
use App\Infrastructure\Proxmox\PveHttpTransport;
use App\Infrastructure\Proxmox\PveJsonEnvelopeDecoder;
use App\Infrastructure\Proxmox\PveRetryDelay;
use App\Infrastructure\Proxmox\PveRetryPolicy;
use App\Infrastructure\Proxmox\PveTlsConfiguration;
use App\Infrastructure\Proxmox\PveVersionReader;
use App\Infrastructure\Validation\AsciiPatternValidator;
use InvalidArgumentException;
use stdClass;

/**
 * Performs only the onboarding contract's read-only probes. There is no
 * generic request method through which a caller could reach a write route.
 */
final readonly class NativeOnboardingRemoteGateway implements OnboardingRemoteGateway
{
    /** @var list<array{string, string, string}> */
    private const array PVE_SCAN_PROPAGATION_PROBES = [
        ['/', 'Sys.Audit', '/access'],
        ['/nodes', 'Sys.Audit', '/nodes/hoddmimir-propagation-probe'],
        ['/vms', 'VM.Audit', '/vms/999999999'],
        ['/pool', 'Pool.Audit', '/pool/hoddmimir-propagation-probe'],
        ['/storage', 'Datastore.Audit', '/storage/hoddmimir-propagation-probe'],
    ];

    /** @var list<array{string, string, string}> */
    private const array PVE_BACKUP_PROPAGATION_PROBES = [
        ['/vms', 'VM.Backup', '/vms/999999999'],
        ['/storage', 'Datastore.AllocateSpace', '/storage/hoddmimir-propagation-probe'],
    ];

    private const int MAXIMUM_PVE_ACL_ROWS = 4096;
    private const int MAXIMUM_PVE_ROLES = 4096;
    private const int MAXIMUM_PVE_PERMISSION_PATHS = 4096;
    private const int MAXIMUM_PVE_PRIVILEGES_PER_PATH = 256;
    private const int MAXIMUM_PVE_PRIVILEGES_TOTAL = 65536;

    public function __construct(
        private PveHttpClientFactory $pveClients,
        private PveRetryDelay $pveRetryDelay,
        private PbsHttpClientFactory $pbsClients,
        private PbsRetryDelay $pbsRetryDelay,
    ) {
    }

    public function verify(OnboardingActivationCommand $command): OnboardingRemoteEvidence
    {
        return OnboardingProduct::Pve === $command->product
            ? $this->verifyPve($command)
            : $this->verifyPbs($command);
    }

    private function verifyPve(OnboardingActivationCommand $command): OnboardingRemoteEvidence
    {
        $scan = $this->pveTransport($command, $command->credential(OnboardingCredentialKind::Scan));
        $backup = $this->pveTransport($command, $command->credential(OnboardingCredentialKind::Backup));
        $versionReader = new PveVersionReader();

        $scanVersion = $this->pveProbe(
            fn () => $versionReader->read($scan->get(['version'])),
            $command,
            OnboardingCredentialKind::Scan,
        );
        $backupVersion = $this->pveProbe(
            fn () => $versionReader->read($backup->get(['version'])),
            $command,
            OnboardingCredentialKind::Backup,
        );
        $roles = $this->pveProbe(
            fn (): array => $this->pveRoles($scan->get(['access', 'roles'])),
            $command,
            OnboardingCredentialKind::Scan,
        );
        $scanMatrix = $this->pveProbe(
            fn (): array => $this->pvePermissionMatrix($scan->get(['access', 'permissions'])),
            $command,
            OnboardingCredentialKind::Scan,
        );
        $backupMatrix = $this->pveProbe(
            fn (): array => $this->pvePermissionMatrix($backup->get(['access', 'permissions'])),
            $command,
            OnboardingCredentialKind::Backup,
        );
        $aclPaths = $this->pveProbe(
            fn (): array => $this->pveAclPaths($scan->get(['access', 'acl'])),
            $command,
            OnboardingCredentialKind::Scan,
        );
        $scanPropagation = $this->pvePropagationEvidence(
            $scan,
            self::PVE_SCAN_PROPAGATION_PROBES,
            $command,
            OnboardingCredentialKind::Scan,
        );
        $backupPropagation = $this->pvePropagationEvidence(
            $backup,
            self::PVE_BACKUP_PROPAGATION_PROBES,
            $command,
            OnboardingCredentialKind::Backup,
        );
        $scanPermissions = $this->pvePermissions(
            $scanMatrix,
            $scanPropagation,
            $aclPaths,
            self::PVE_SCAN_PROPAGATION_PROBES,
        );
        $backupPermissions = $this->pvePermissions(
            $backupMatrix,
            $backupPropagation,
            $aclPaths,
            self::PVE_BACKUP_PROPAGATION_PROBES,
        );

        return new OnboardingRemoteEvidence(true, [
            new OnboardingIdentityEvidence(
                OnboardingCredentialKind::Scan,
                OnboardingProduct::Pve,
                $scanVersion->major,
                $scanVersion->minor,
                $scanVersion->version,
                $scanPermissions,
            ),
            new OnboardingIdentityEvidence(
                OnboardingCredentialKind::Backup,
                OnboardingProduct::Pve,
                $backupVersion->major,
                $backupVersion->minor,
                $backupVersion->version,
                $backupPermissions,
            ),
        ], $roles);
    }

    private function verifyPbs(OnboardingActivationCommand $command): OnboardingRemoteEvidence
    {
        $transport = $this->pbsTransport($command, $command->credential(OnboardingCredentialKind::Scan));
        $version = $this->pbsProbe(
            fn () => (new PbsVersionReader())->read($transport->get(PbsRequest::version())),
            $command,
        );
        $permissionReader = new PbsPermissionReader();
        $permissions = [];
        $matrix = $this->pbsProbe(
            fn (): array => $permissionReader->readAll($transport->get(PbsRequest::permissions())),
            $command,
        );
        foreach ($matrix as $effective) {
            foreach ($effective->privileges as $privilege => $propagated) {
                $permissions[] = new OnboardingPermission($effective->path, $privilege, true, $propagated);
            }
        }
        foreach (['/system/status', '/system/tasks', '/datastore', '/remote'] as $path) {
            $effective = $this->pbsProbe(
                fn () => $permissionReader->read($transport->get(PbsRequest::permission($path)), $path),
                $command,
            );
            foreach ($effective->privileges as $privilege => $propagated) {
                $permissions[] = new OnboardingPermission($path, $privilege, true, $propagated);
            }
        }

        return new OnboardingRemoteEvidence(true, [
            new OnboardingIdentityEvidence(
                OnboardingCredentialKind::Scan,
                OnboardingProduct::Pbs,
                $version->major,
                $version->minor,
                $version->version,
                $permissions,
            ),
        ]);
    }

    private function pveTransport(OnboardingActivationCommand $command, OnboardingCredential $credential): PveHttpTransport
    {
        [$user, $token] = $this->tokenParts($credential->tokenId);
        $identity = PveApiTokenIdentity::fromUserAndTokenId($user, $token);
        $tls = $this->pveTls($command);

        return new PveHttpTransport(
            $this->pveClients->create($tls),
            new PveApiUrlBuilder($command->endpoint->host, $command->endpoint->port),
            new PveOnboardingTokenAuthenticator($identity, $credential->secret),
            new PveRetryPolicy(),
            $this->pveRetryDelay,
            new PveJsonEnvelopeDecoder(),
            new OnboardingReadCheckpoint(),
        );
    }

    private function pbsTransport(OnboardingActivationCommand $command, OnboardingCredential $credential): PbsHttpTransport
    {
        [$userAtRealm, $token] = $this->tokenParts($credential->tokenId);
        [$user, $realm] = explode('@', $userAtRealm, 2);
        $identity = PbsApiTokenIdentity::fromParts($user, $realm, $token);
        $tls = $this->pbsTls($command);

        return new PbsHttpTransport(
            $this->pbsClients->create($tls),
            new PbsApiUrlBuilder($command->endpoint->host, $command->endpoint->port),
            new PbsOnboardingTokenAuthenticator($identity, $credential->secret),
            new PbsRetryPolicy(),
            $this->pbsRetryDelay,
            new PbsJsonEnvelopeDecoder(),
            new OnboardingReadCheckpoint(),
        );
    }

    /**
     * PVE documents this matrix as privilege => propagation flag. A defined
     * zero is therefore an effective grant at the exact path, not a denial.
     *
     * @return array<string, array<string, bool>>
     */
    private function pvePermissionMatrix(mixed $data): array
    {
        $paths = $data instanceof stdClass ? get_object_vars($data) : $data;
        if (!is_array($paths) || array_is_list($paths) || count($paths) > self::MAXIMUM_PVE_PERMISSION_PATHS) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }
        /** @var array<string, array<array-key, mixed>> $normalizedPaths */
        $normalizedPaths = [];
        $totalPrivileges = 0;
        foreach ($paths as $path => $privileges) {
            $privileges = $privileges instanceof stdClass ? get_object_vars($privileges) : $privileges;
            if (!is_array($privileges) || count($privileges) > self::MAXIMUM_PVE_PRIVILEGES_PER_PATH) {
                throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
            }
            $totalPrivileges += count($privileges);
            if ($totalPrivileges > self::MAXIMUM_PVE_PRIVILEGES_TOTAL) {
                throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
            }
            $normalizedPaths[(string) $path] = $privileges;
        }

        $result = [];
        foreach ($normalizedPaths as $path => $privileges) {
            if (!AsciiPatternValidator::matches('/\A\/(?:[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*)?\z/D', $path)) {
                throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
            }
            foreach ($privileges as $privilege => $granted) {
                if (0 !== $granted && 1 !== $granted) {
                    throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
                }
                try {
                    new OnboardingPermission($path, (string) $privilege, true, 1 === $granted);
                } catch (InvalidArgumentException) {
                    throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
                }
                $result[$path][(string) $privilege] = 1 === $granted;
            }
        }

        return $result;
    }

    /**
     * @param array<string, array<string, bool>> $matrix
     * @param array<string, array<string, bool>> $propagation
     * @param list<string>                       $aclPaths
     * @param list<array{string, string, string}> $required
     * @return list<OnboardingPermission>
     */
    private function pvePermissions(array $matrix, array $propagation, array $aclPaths, array $required): array
    {
        $result = [];
        foreach ($matrix as $path => $privileges) {
            foreach ($privileges as $privilege => $reportedPropagation) {
                $probed = $propagation[$path][$privilege] ?? null;
                $result[] = new OnboardingPermission(
                    $path,
                    $privilege,
                    true,
                    null === $probed ? $reportedPropagation : ($reportedPropagation && $probed),
                );
            }
        }

        $evidencePaths = array_fill_keys([...array_keys($matrix), ...$aclPaths], true);
        foreach (array_keys($evidencePaths) as $path) {
            foreach ($required as [$familyRoot, $privilege]) {
                if (!$this->isPathInFamily($path, $familyRoot) || isset($matrix[$path][$privilege])) {
                    continue;
                }
                // The ACL path is known to exist, but PVE's effective matrix
                // omitted the required privilege (and can omit the whole path
                // after NoAccess). Preserve that negative evidence explicitly.
                $result[] = new OnboardingPermission($path, $privilege, false, false);
            }
        }

        return $result;
    }

    /**
     * @param list<array{string, string, string}> $probes
     * @return array<string, array<string, bool>>
     */
    private function pvePropagationEvidence(
        PveHttpTransport $transport,
        array $probes,
        OnboardingActivationCommand $command,
        OnboardingCredentialKind $credential,
    ): array {
        $result = [];
        foreach ($probes as [$root, $privilege, $child]) {
            $result[$root][$privilege] = $this->pveProbe(
                fn (): bool => $this->pveScopedPropagation(
                    $transport->get(['access', 'permissions'], ['path' => $child]),
                    $child,
                    $privilege,
                ),
                $command,
                $credential,
            );
        }

        return $result;
    }

    private function pveScopedPropagation(mixed $data, string $path, string $privilege): bool
    {
        $matrix = $this->pvePermissionMatrix($data);
        if ([] !== $matrix && [$path] !== array_keys($matrix)) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }

        return true === ($matrix[$path][$privilege] ?? null);
    }

    /** @return list<string> */
    private function pveAclPaths(mixed $data): array
    {
        if (!is_array($data)) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }
        if (count($data) > self::MAXIMUM_PVE_ACL_ROWS) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }
        $paths = [];
        $rows = [];
        foreach ($data as $row) {
            if (!$row instanceof stdClass) {
                throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
            }
            $row = get_object_vars($row);
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if (['path', 'propagate', 'roleid', 'type', 'ugid'] !== $keys) {
                throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
            }
            $path = $row['path'];
            $type = $row['type'];
            $ugid = $row['ugid'];
            $role = $row['roleid'];
            $propagate = $row['propagate'];
            if (!is_string($path) || !is_string($type) || !is_string($ugid) || !is_string($role)
                || ('user' !== $type && 'group' !== $type && 'token' !== $type)
                || (0 !== $propagate && 1 !== $propagate)
                || !AsciiPatternValidator::matches('/\A\/(?:[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*)?\z/D', $path)
                || !AsciiPatternValidator::matches('/\A[A-Za-z0-9._@!-]{1,255}\z/D', $ugid)
                || !AsciiPatternValidator::matches('/\A[A-Za-z0-9._-]{1,255}\z/D', $role)) {
                throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
            }
            $key = $path."\0".$type."\0".$ugid."\0".$role;
            if (isset($rows[$key])) {
                throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
            }
            $rows[$key] = true;
            $paths[$path] = true;
        }

        $result = array_keys($paths);
        sort($result, SORT_STRING);
        return $result;
    }

    private function isPathInFamily(string $path, string $familyRoot): bool
    {
        if ('/' === $familyRoot || $path === $familyRoot) {
            return true;
        }
        $prefix = $familyRoot.'/';
        $prefixLength = strlen($prefix);
        if (strlen($path) < $prefixLength) {
            return false;
        }
        for ($index = 0; $index < $prefixLength; ++$index) {
            if ($path[$index] !== $prefix[$index]) {
                return false;
            }
        }

        return true;
    }

    /** @return list<OnboardingRoleDefinition> */
    private function pveRoles(mixed $data): array
    {
        if (!is_array($data) || !array_is_list($data) || count($data) > self::MAXIMUM_PVE_ROLES) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }
        $result = [];
        foreach ($data as $row) {
            $row = $row instanceof stdClass ? get_object_vars($row) : $row;
            if (!is_array($row) || !is_string($row['roleid'] ?? null) || !is_string($row['privs'] ?? null)) {
                throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
            }
            $privileges = '' === $row['privs'] ? [] : explode(',', $row['privs']);
            try {
                $result[] = new OnboardingRoleDefinition($row['roleid'], $privileges);
            } catch (InvalidArgumentException) {
                throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
            }
        }

        return $result;
    }

    /** @return array{string, string} */
    private function tokenParts(string $tokenId): array
    {
        /** @var array{string, string} $parts Guaranteed by OnboardingCredential. */
        $parts = explode('!', $tokenId, 2);
        return $parts;
    }

    private function pveTls(OnboardingActivationCommand $command): PveTlsConfiguration
    {
        if (OnboardingTlsMode::SystemCa === $command->endpoint->tlsMode) {
            return PveTlsConfiguration::systemCa();
        }
        if (OnboardingTlsMode::CustomCa === $command->endpoint->tlsMode) {
            return PveTlsConfiguration::customCa(
                PveCustomCaCertificate::fromPem((string) $command->endpoint->customCaPem),
            );
        }

        return PveTlsConfiguration::certificateFingerprint(
            PveCertificateFingerprint::fromSha256((string) $command->endpoint->sha256Fingerprint),
        );
    }

    private function pbsTls(OnboardingActivationCommand $command): PbsTlsConfiguration
    {
        if (OnboardingTlsMode::SystemCa === $command->endpoint->tlsMode) {
            return PbsTlsConfiguration::systemCa();
        }
        if (OnboardingTlsMode::CustomCa === $command->endpoint->tlsMode) {
            return PbsTlsConfiguration::customCa(
                PbsCustomCaCertificate::fromPem((string) $command->endpoint->customCaPem),
            );
        }

        return PbsTlsConfiguration::certificateFingerprint(
            PbsCertificateFingerprint::fromSha256((string) $command->endpoint->sha256Fingerprint),
        );
    }

    /**
     * @template T
     * @param callable(): T $probe
     * @return T
     */
    private function pveProbe(callable $probe, OnboardingActivationCommand $command, OnboardingCredentialKind $credential): mixed
    {
        try {
            return $probe();
        } catch (PveReadFailure $failure) {
            throw new OnboardingRemoteFailure(
                $this->failureCode($failure->failureCode, $command->endpoint->tlsMode),
                $credential,
            );
        }
    }

    /**
     * @template T
     * @param callable(): T $probe
     * @return T
     */
    private function pbsProbe(callable $probe, OnboardingActivationCommand $command): mixed
    {
        try {
            return $probe();
        } catch (PbsReadFailure $failure) {
            throw new OnboardingRemoteFailure(
                $this->failureCode($failure->failureCode, $command->endpoint->tlsMode),
                OnboardingCredentialKind::Scan,
            );
        }
    }

    private function failureCode(PveReadFailureCode|PbsReadFailureCode $failure, OnboardingTlsMode $tls): OnboardingIssueCode
    {
        return match ($failure) {
            PveReadFailureCode::Authentication, PbsReadFailureCode::Authentication,
            PveReadFailureCode::CredentialUnavailable, PbsReadFailureCode::CredentialUnavailable,
            PveReadFailureCode::PermissionDenied, PbsReadFailureCode::PermissionDenied => OnboardingIssueCode::AuthenticationFailed,
            PveReadFailureCode::Transport, PbsReadFailureCode::Transport => OnboardingTlsMode::Sha256Fingerprint === $tls
                ? OnboardingIssueCode::TlsFingerprintMismatch
                : OnboardingIssueCode::TlsVerificationFailed,
            PveReadFailureCode::RateLimited, PbsReadFailureCode::RateLimited,
            PveReadFailureCode::RemoteUnavailable, PbsReadFailureCode::RemoteUnavailable,
            PveReadFailureCode::HttpStatus, PbsReadFailureCode::HttpStatus => OnboardingIssueCode::RemoteUnavailable,
            PveReadFailureCode::UnsupportedVersion, PbsReadFailureCode::UnsupportedVersion => OnboardingIssueCode::UnsupportedVersion,
            PveReadFailureCode::NotFound, PbsReadFailureCode::NotFound,
            PveReadFailureCode::InvalidEnvelope, PbsReadFailureCode::InvalidEnvelope => OnboardingIssueCode::InvalidRemoteResponse,
            default => OnboardingIssueCode::InvalidRemoteResponse,
        };
    }
}
