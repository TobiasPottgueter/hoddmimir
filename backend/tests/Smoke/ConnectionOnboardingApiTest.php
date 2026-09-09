<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Application\Configuration\ConfigurationCommand;
use App\Application\Configuration\ConfigurationCommandResult;
use App\Application\Configuration\Connection\ConnectionCommandRepository;
use App\Application\Configuration\Connection\ConnectionReadModel;
use App\Application\Configuration\Connection\Onboarding\OnboardingActivationCommand;
use App\Application\Configuration\Connection\Onboarding\OnboardingActivationRepository;
use App\Application\Configuration\Connection\Onboarding\OnboardingMutationResult;
use App\Application\Configuration\Connection\Onboarding\OnboardingMutationStatus;
use App\Application\Configuration\Connection\Onboarding\OnboardingVerification;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Domain\Security\Permission;
use App\Infrastructure\Persistence\MariaDb\DbalConnectionAdministration;
use App\Infrastructure\Persistence\MariaDb\DbalOnboardingActivationRepository;
use App\Infrastructure\Proxmox\Onboarding\NativeOnboardingCustomCaValidator;
use App\Presentation\Http\OnboardingCommandFactory;
use App\Tests\Fakes\TestHttpRequestAuthenticator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConnectionOnboardingApiTest extends WebTestCase
{
    private const string CONNECTION = '00112233-4455-6677-8899-aabbccddeeff';
    private const string ENDPOINT = '11112222-3333-4444-8555-666677778888';
    private KernelBrowser $client;
    private OnboardingApiRepository $repository;
    private TestHttpRequestAuthenticator $authenticator;
    private string $csrf;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->repository = new OnboardingApiRepository();
        self::getContainer()->set(DbalOnboardingActivationRepository::class, $this->repository);
        self::getContainer()->set(DbalConnectionAdministration::class, new LegacyConnectionApiRepository());
        $authenticator = self::getContainer()->get(TestHttpRequestAuthenticator::class);
        self::assertInstanceOf(TestHttpRequestAuthenticator::class, $authenticator);
        $this->authenticator = $authenticator;
        $this->authenticator->usePermissions([Permission::BackupConfigurationManage]);
        $this->csrf = rtrim(strtr(base64_encode(str_repeat("\x03", 32)), '+/', '-_'), '=');
    }

    public function testGuidanceIsPermissionProtectedClosedAndSecretFree(): void
    {
        $this->client->request('GET', '/api/v1/connections/onboarding/guidance/pve');
        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame('pve', $body['product'] ?? null);
        self::assertIsArray($body['commands'] ?? null);
        $serialized = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('qa-pve-scan-secret', $serialized);
        self::assertStringNotContainsString('qa-pve-backup-secret', $serialized);
        self::assertStringNotContainsString('adminToken', $serialized);

        $this->client->request('GET', '/api/v1/connections/onboarding/guidance/unknown');
        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/api/v1/connections/onboarding/guidance/pve?extra=1');
        self::assertResponseStatusCodeSame(400);

        $this->authenticator->usePermissions([]);
        $this->client->request('GET', '/api/v1/connections/onboarding/guidance/pve');
        self::assertResponseStatusCodeSame(403);
    }

    public function testPveAndPbsActivationUseRealControllerServiceVerifierAndTestOnlyRemoteBoundary(): void
    {
        $this->mutate('/api/v1/connections/onboarding/activate', $this->pveBody());
        self::assertResponseIsSuccessful();
        $pve = $this->json();
        $pveVerification = $this->object($pve['verification'] ?? null);
        self::assertSame('applied', $pve['status'] ?? null);
        self::assertSame('first_automatic_scan_pending', $pve['onboardingStatus'] ?? null);
        self::assertSame('backup_permissions_verified', $pveVerification['backupPermissions'] ?? null);
        $connectionId = $pve['connectionId'] ?? null;
        self::assertIsString($connectionId);
        self::assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', $connectionId);

        $this->mutate('/api/v1/connections/onboarding/activate', $this->pbsBody(), 'pbs-key');
        self::assertResponseIsSuccessful();
        $pbs = $this->json();
        $pbsVerification = $this->object($pbs['verification'] ?? null);
        self::assertNull($pbsVerification['backupPermissions'] ?? null);
        self::assertSame('pbs', $pbsVerification['detectedProduct'] ?? null);
        self::assertSame(2, $this->repository->activations);
    }

    public function testLostActivationResponseCanBeReplayedAndChangedPayloadConflicts(): void
    {
        $repository = $this->repository;
        $repository->simulateIdempotencyReplay();
        self::getContainer()->set(
            OnboardingCommandFactory::class,
            new OnboardingCommandFactory(new SequentialOnboardingHttpIds(), new NativeOnboardingCustomCaValidator()),
        );

        $body = $this->pveBody();
        $this->mutate('/api/v1/connections/onboarding/activate', $body, 'lost-200-retry');
        self::assertResponseIsSuccessful();
        $applied = $this->json();
        self::assertSame('applied', $applied['status'] ?? null);

        // Model a client that did not receive the first successful response.
        $this->mutate('/api/v1/connections/onboarding/activate', $body, 'lost-200-retry');
        self::assertResponseIsSuccessful();
        $replayed = $this->json();
        self::assertSame('replayed', $replayed['status'] ?? null);
        self::assertSame($applied['connectionId'] ?? null, $replayed['connectionId'] ?? null);
        self::assertCount(2, $repository->attemptConnectionIds);
        self::assertNotSame($repository->attemptConnectionIds[0], $repository->attemptConnectionIds[1]);
        self::assertSame(1, $repository->applied);

        $changed = $body;
        $changed['displayName'] = 'PVE QA changed';
        $this->mutate('/api/v1/connections/onboarding/activate', $changed, 'lost-200-retry');
        self::assertResponseStatusCodeSame(409);
        $error = $this->object($this->json()['error'] ?? null);
        self::assertSame('revision_conflict', $error['code'] ?? null);
        self::assertSame(1, $error['currentRevision'] ?? null);
        self::assertSame(1, $repository->applied);
    }

    public function testFailuresAreTypedSanitizedAndNeverApplyPartialState(): void
    {
        $wrongSecret = $this->pveBody();
        $wrongSecret['credentials']['scan']['tokenSecret'] = 'WRONG-SECRET-SENTINEL';
        $this->mutate('/api/v1/connections/onboarding/activate', $wrongSecret);
        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('WRONG-SECRET-SENTINEL', (string) $this->client->getResponse()->getContent());
        $authenticationError = $this->object($this->json()['error'] ?? null);
        $authenticationIssues = $authenticationError['issues'] ?? null;
        self::assertIsArray($authenticationIssues);
        $authenticationIssue = $this->object($authenticationIssues[0] ?? null);
        self::assertSame('authentication_failed', $authenticationIssue['code'] ?? null);

        $missing = $this->pveBody();
        $missing['endpoint']['host'] = 'pve-missing-permission.qa.invalid';
        $this->mutate('/api/v1/connections/onboarding/activate', $missing, 'missing-right');
        self::assertResponseStatusCodeSame(422);
        $missingError = $this->object($this->json()['error'] ?? null);
        $missingIssues = $missingError['issues'] ?? null;
        self::assertIsArray($missingIssues);
        self::assertContains('required_permission_missing', array_column($missingIssues, 'code'));

        $forbidden = $this->pveBody();
        $forbidden['endpoint']['host'] = 'pve-forbidden-permission.qa.invalid';
        $this->mutate('/api/v1/connections/onboarding/activate', $forbidden, 'forbidden-right');
        self::assertResponseStatusCodeSame(422);
        $forbiddenError = $this->object($this->json()['error'] ?? null);
        $forbiddenIssues = $forbiddenError['issues'] ?? null;
        self::assertIsArray($forbiddenIssues);
        self::assertContains('forbidden_permission_present', array_column($forbiddenIssues, 'code'));
        self::assertContains('VM.PowerMgmt', array_column($forbiddenIssues, 'privilege'));
        self::assertSame(0, $this->repository->applied);
    }

    public function testReadOnlyWarningStillActivatesAndRotationRequiresExactEndpoint(): void
    {
        $warning = $this->pveBody();
        $warning['endpoint']['host'] = 'pve-readonly-warning.qa.invalid';
        $this->mutate('/api/v1/connections/onboarding/activate', $warning);
        self::assertResponseIsSuccessful();
        $warningVerification = $this->object($this->json()['verification'] ?? null);
        $warnings = $warningVerification['warnings'] ?? null;
        self::assertIsArray($warnings);
        $firstWarning = $this->object($warnings[0] ?? null);
        self::assertSame('additional_read_only_permission', $firstWarning['code'] ?? null);

        $rotation = $this->pveBody(4);
        $rotation['endpointId'] = self::ENDPOINT;
        $this->mutate('/api/v1/connections/'.self::CONNECTION.'/onboarding/rotate', $rotation, 'rotate-key');
        self::assertResponseIsSuccessful();
        $lastCommand = $this->repository->lastCommand ?? self::fail('The rotation command was not recorded.');
        self::assertSame(hex2bin(str_replace('-', '', self::CONNECTION)), $lastCommand->connectionId);
        self::assertSame(hex2bin(str_replace('-', '', self::ENDPOINT)), $lastCommand->endpointId);

        unset($rotation['endpointId']);
        $this->mutate('/api/v1/connections/'.self::CONNECTION.'/onboarding/rotate', $rotation, 'missing-endpoint');
        self::assertResponseStatusCodeSame(400);
    }

    public function testVerifiedFailoverEndpointAddAndUpdateUseFullReadOnlyVerification(): void
    {
        $endpoint = $this->pveBody(2);
        $endpoint['endpoint']['host'] = 'pve-failover.qa.invalid';
        $this->mutate('/api/v1/connections/'.self::CONNECTION.'/onboarding/endpoints', $endpoint, 'endpoint-add');
        self::assertResponseIsSuccessful();
        self::assertSame('first_automatic_scan_pending', $this->json()['onboardingStatus'] ?? null);
        $endpointVerification = $this->object($this->json()['verification'] ?? null);
        self::assertSame('first_automatic_scan_pending', $endpointVerification['inventory'] ?? null);
        self::assertNull($this->repository->lastCommand?->endpointId);

        $endpoint['endpoint']['host'] = 'pve-onboarding.qa.invalid';
        $this->client->jsonRequest('PUT', '/api/v1/connections/'.self::CONNECTION.'/onboarding/endpoints/'.self::ENDPOINT, $endpoint, [
            'HTTP_X_CSRF_TOKEN' => $this->csrf,
            'HTTP_IDEMPOTENCY_KEY' => 'endpoint-update',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('first_automatic_scan_pending', $this->json()['onboardingStatus'] ?? null);
        self::assertSame(hex2bin(str_replace('-', '', self::ENDPOINT)), $this->repository->lastCommand?->endpointId);

        $endpoint['endpoint']['host'] = 'pve-forbidden-permission.qa.invalid';
        $this->mutate('/api/v1/connections/'.self::CONNECTION.'/onboarding/endpoints', $endpoint, 'unsafe-endpoint');
        self::assertResponseStatusCodeSame(422);
    }

    public function testCsrfIdempotencyClosedBodyPermissionConflictAndRemovedLegacyRoutes(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/connections/onboarding/activate', $this->pveBody(), ['HTTP_IDEMPOTENCY_KEY' => 'missing-csrf']);
        self::assertResponseStatusCodeSame(403);
        $this->client->jsonRequest('POST', '/api/v1/connections/onboarding/activate', $this->pveBody(), ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertResponseStatusCodeSame(400);
        $open = $this->pveBody(); $open['adminToken'] = 'forbidden';
        $this->mutate('/api/v1/connections/onboarding/activate', $open, 'open-body');
        self::assertResponseStatusCodeSame(400);
        $urlHost = $this->pveBody(); $urlHost['endpoint']['host'] = 'https://pve.example.test';
        $this->mutate('/api/v1/connections/onboarding/activate', $urlHost, 'url-host');
        self::assertResponseStatusCodeSame(400);
        $invalidCa = $this->pveBody();
        $invalidCa['endpoint']['tlsMode'] = 'custom_ca';
        $invalidCa['endpoint']['customCaPem'] = "-----BEGIN CERTIFICATE-----\nINVALID\n-----END CERTIFICATE-----";
        $this->mutate('/api/v1/connections/onboarding/activate', $invalidCa, 'invalid-ca');
        self::assertResponseStatusCodeSame(400);

        $this->repository->forceConflict = true;
        $this->mutate('/api/v1/connections/onboarding/activate', $this->pveBody(), 'conflict');
        self::assertResponseStatusCodeSame(409);
        $conflict = $this->object($this->json()['error'] ?? null);
        self::assertSame(9, $conflict['currentRevision'] ?? null);

        $headers = ['HTTP_X_CSRF_TOKEN' => $this->csrf, 'HTTP_IDEMPOTENCY_KEY' => 'removed-legacy'];
        $this->client->jsonRequest('POST', '/api/v1/connections', ['expectedRevision' => 0], $headers);
        self::assertResponseStatusCodeSame(405);
        $this->client->jsonRequest('POST', '/api/v1/connections/'.self::CONNECTION.'/enable', ['expectedRevision' => 1], $headers);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/api/v1/connections/'.self::CONNECTION.'/endpoints', ['expectedRevision' => 1], $headers);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('PUT', '/api/v1/connections/'.self::CONNECTION.'/endpoints/'.self::ENDPOINT, ['expectedRevision' => 1], $headers);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/api/v1/connections/'.self::CONNECTION.'/credentials/rotate', ['expectedRevision' => 1], $headers);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array{
     *     expectedRevision: int,
     *     product: string,
     *     displayName: string,
     *     endpoint: array{host: string, port: int, tlsMode: string, customCaPem: ?string, sha256Fingerprint: ?string},
     *     credentials: array{
     *         scan: array{tokenId: string, tokenSecret: string},
     *         backup: array{tokenId: string, tokenSecret: string}
     *     }
     * }
     */
    private function pveBody(int $revision = 0): array
    {
        return [
            'expectedRevision' => $revision, 'product' => 'pve', 'displayName' => 'PVE QA',
            'endpoint' => ['host' => 'pve-onboarding.qa.invalid', 'port' => 8006, 'tlsMode' => 'system_ca', 'customCaPem' => null, 'sha256Fingerprint' => null],
            'credentials' => [
                'scan' => ['tokenId' => 'hoddmimir@pve!scan', 'tokenSecret' => 'qa-pve-scan-secret'],
                'backup' => ['tokenId' => 'hoddmimir@pve!backup', 'tokenSecret' => 'qa-pve-backup-secret'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function pbsBody(): array
    {
        return [
            'expectedRevision' => 0, 'product' => 'pbs', 'displayName' => 'PBS QA',
            'endpoint' => ['host' => 'pbs-onboarding.qa.invalid', 'port' => 8007, 'tlsMode' => 'system_ca', 'customCaPem' => null, 'sha256Fingerprint' => null],
            'credentials' => ['scan' => ['tokenId' => 'hoddmimir@pbs!scan', 'tokenSecret' => '00000000-0000-0000-0000-000000000000']],
        ];
    }

    /** @param array<string, mixed> $body */
    private function mutate(string $path, array $body, string $key = 'onboarding-key'): void
    {
        $this->client->jsonRequest('POST', $path, $body, ['HTTP_X_CSRF_TOKEN' => $this->csrf, 'HTTP_IDEMPOTENCY_KEY' => $key]);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return array<string, mixed> */
    private function object(mixed $value): array
    {
        self::assertIsArray($value);
        /** @var array<string, mixed> $value */
        return $value;
    }
}

final class OnboardingApiRepository implements OnboardingActivationRepository
{
    public int $activations = 0;
    public int $applied = 0;
    public bool $forceConflict = false;
    public ?OnboardingActivationCommand $lastCommand = null;
    /** @var list<string> */ public array $attemptConnectionIds = [];
    private bool $replaySimulation = false;
    private ?string $idempotencyKey = null;
    private ?string $payloadHash = null;
    private ?string $appliedConnectionId = null;

    public function simulateIdempotencyReplay(): void
    {
        $this->replaySimulation = true;
    }

    public function activate(OnboardingActivationCommand $command, OnboardingVerification $verification, AuthenticatedPrincipal $principal): OnboardingMutationResult
    {
        ++$this->activations; $this->lastCommand = $command;
        $this->attemptConnectionIds[] = $command->connectionId;
        if ($this->replaySimulation && null !== $this->idempotencyKey) {
            if ($this->idempotencyKey === $command->idempotencyKey
                && null !== $this->payloadHash
                && hash_equals($this->payloadHash, $command->payloadHash)) {
                return new OnboardingMutationResult(
                    OnboardingMutationStatus::Replayed,
                    $this->appliedConnectionId ?? throw new \LogicException('The applied connection identifier is unavailable.'),
                    1,
                    $verification,
                );
            }

            return new OnboardingMutationResult(OnboardingMutationStatus::Conflict, $command->connectionId, 1);
        }
        if ($this->forceConflict) return new OnboardingMutationResult(OnboardingMutationStatus::Conflict, $command->connectionId, 9);
        if (!$verification->passed()) return new OnboardingMutationResult(OnboardingMutationStatus::Rejected, $command->connectionId, null, $verification);
        if ($this->replaySimulation) {
            $this->idempotencyKey = $command->idempotencyKey;
            $this->payloadHash = $command->payloadHash;
            $this->appliedConnectionId = $command->connectionId;
        }
        ++$this->applied;
        return new OnboardingMutationResult(OnboardingMutationStatus::Applied, $command->connectionId, $command->expectedRevision + 1, $verification);
    }
    public function record(OnboardingActivationCommand $command, OnboardingMutationResult $result, AuthenticatedPrincipal $principal): OnboardingMutationResult
    {
        $this->lastCommand = $command; return $result;
    }
}

final class SequentialOnboardingHttpIds implements SecurityIdentifierGenerator
{
    private int $next = 1;

    public function generate(): string
    {
        return pack('N4', 0, 0, 0, $this->next++);
    }
}

final class LegacyConnectionApiRepository implements ConnectionCommandRepository, ConnectionReadModel
{
    public function execute(ConfigurationCommand $command, AuthenticatedPrincipal $principal): ConfigurationCommandResult { throw new \LogicException('Legacy mutation bypassed the handler.'); }
    public function record(ConfigurationCommand $command, AuthenticatedPrincipal $principal, ConfigurationCommandResult $result): ConfigurationCommandResult { return $result; }
    public function connections(PageRequest $page): array
    {
        return ['items' => [], 'page' => ['limit' => $page->limit, 'count' => 0, 'hasMore' => false, 'nextCursor' => null]];
    }
    public function connection(string $id): ?array { return null; }
}
