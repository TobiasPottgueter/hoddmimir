<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Application\Configuration\ConfigurationCommand;
use App\Application\Configuration\ConfigurationCommandResult;
use App\Application\Configuration\ConfigurationCommandStatus;
use App\Application\Configuration\Policy\PolicyActivationEvidence;
use App\Application\Configuration\Policy\PolicyActivationEvidenceProvider;
use App\Application\Configuration\Policy\PolicyCommandRepository;
use App\Application\Configuration\Selection\SelectionCommandRepository;
use App\Application\Configuration\Target\TargetCandidateEvidence;
use App\Application\Configuration\Target\TargetCandidateEvidenceProvider;
use App\Application\Configuration\Target\TargetCommandRepository;
use App\Application\Configuration\Target\TargetExecutorEvidenceProvider;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\OpaqueToken;
use App\Application\Security\Auth\SessionResult;
use App\Domain\Policy\BackupMode;
use App\Domain\Policy\BackupPolicy;
use App\Domain\Policy\Compression;
use App\Domain\Policy\PolicyId;
use App\Domain\Policy\PolicyPriority;
use App\Domain\Policy\PolicyRevision;
use App\Domain\Policy\PolicyThresholds;
use App\Domain\Policy\RetentionPolicy;
use App\Domain\Policy\Schedule;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\SessionWindow;
use App\Domain\Security\UserId;
use App\Domain\Target\ActivationEvidenceObservation;
use App\Domain\Target\AllowedNodes;
use App\Domain\Target\BackupTarget;
use App\Domain\Target\BackupTargetId;
use App\Domain\Target\ConcurrencyPolicy;
use App\Domain\Target\MinimumFreeBytes;
use App\Domain\Target\TargetRevision;
use App\Domain\Target\TargetStatus;
use App\Presentation\Http\Auth\HttpRequestAuthenticator;
use App\Infrastructure\Persistence\MariaDb\DbalConfigurationCommandRepository;
use App\Infrastructure\Persistence\MariaDb\DbalActivationEvidenceProvider;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ConfigurationCommandApiTest extends WebTestCase
{
    private const string UUID = '00112233-4455-6677-8899-aabbccddeeff';
    private KernelBrowser $client;
    private CommandStoreFake $store;
    private CommandEvidenceFake $evidence;
    private CommandAuthenticator $authenticator;
    private string $csrf;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->store = new CommandStoreFake();
        $this->evidence = new CommandEvidenceFake();
        self::getContainer()->set(DbalConfigurationCommandRepository::class, $this->store);
        self::getContainer()->set(DbalActivationEvidenceProvider::class, $this->evidence);
        $this->authenticator = new CommandAuthenticator(true);
        self::getContainer()->set(HttpRequestAuthenticator::class, $this->authenticator);
        $this->csrf = rtrim(strtr(base64_encode(str_repeat("\x03", 32)), '+/', '-_'), '=');
    }

    public function testCsrfIdempotencyClosedBodyAndTypedOutcomes(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/backup-targets', $this->targetBody());
        self::assertResponseStatusCodeSame(403);
        $this->request('POST', '/api/v1/backup-targets', $this->targetBody(), idempotency: false);
        self::assertResponseStatusCodeSame(400);
        $unknown = $this->targetBody(); $unknown['unknown'] = true;
        $this->request('POST', '/api/v1/backup-targets', $unknown);
        self::assertResponseStatusCodeSame(400);

        $this->store->result = new ConfigurationCommandResult(ConfigurationCommandStatus::Applied, 1);
        $this->request('POST', '/api/v1/backup-targets', $this->targetBody());
        self::assertResponseIsSuccessful();
        self::assertSame(['status'=>'applied','revision'=>1], $this->json());
        self::assertSame(0, $this->evidence->calls, 'Create must not perform Proxmox I/O or activation evidence reads.');
        $this->store->result = new ConfigurationCommandResult(ConfigurationCommandStatus::Replayed, 1);
        $this->request('POST', '/api/v1/backup-targets', $this->targetBody());
        self::assertSame('replayed', $this->json()['status'] ?? null);
        $this->store->result = new ConfigurationCommandResult(ConfigurationCommandStatus::Conflict, 7);
        $this->request('POST', '/api/v1/backup-targets', $this->targetBody());
        self::assertResponseStatusCodeSame(409);

        $this->store->target = null;
        $this->request('POST', '/api/v1/backup-targets/'.self::UUID.'/enable', ['expectedRevision'=>1]);
        self::assertResponseStatusCodeSame(422);
        $blocked = $this->json();
        self::assertIsArray($blocked['error'] ?? null);
        self::assertSame(['target_missing'], $blocked['error']['blockers'] ?? null);

        $this->store->fail = true;
        $this->request('POST', '/api/v1/backup-targets', $this->targetBody());
        self::assertResponseStatusCodeSame(503);
        self::assertSame(['error'=>['code'=>'configuration_unavailable']], $this->json());
    }

    public function testPermissionAndEveryCommandRouteRemainProtectedAndRoutable(): void
    {
        $this->authenticator->manage = false;
        $this->request('POST', '/api/v1/backup-targets', $this->targetBody());
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->store->records);

        $this->authenticator->manage = true;
        $routes = [
            ['PUT','/api/v1/backup-targets/'.self::UUID,$this->targetBody(1)],
            ['POST','/api/v1/backup-targets/'.self::UUID.'/disable',['expectedRevision'=>1]],
            ['POST','/api/v1/policies',$this->policyBody()],
            ['PUT','/api/v1/policies/'.self::UUID,$this->policyBody(1)],
            ['POST','/api/v1/policies/'.self::UUID.'/enable',['expectedRevision'=>1]],
            ['POST','/api/v1/policies/'.self::UUID.'/disable',['expectedRevision'=>1]],
            ['PUT','/api/v1/policies/'.self::UUID.'/selection',['expectedRevision'=>1,'entries'=>[['id'=>self::UUID,'scope'=>'global','selectionValue'=>'include']]]],
            ['POST','/api/v1/policies/'.self::UUID.'/selection/disable',['expectedRevision'=>1,'entries'=>[['id'=>self::UUID]]]],
            ['PUT','/api/v1/policies/'.self::UUID.'/guest-overrides',['expectedRevision'=>1,'entries'=>[['id'=>self::UUID,'guestId'=>self::UUID,'backupMode'=>'snapshot']]]],
            ['POST','/api/v1/policies/'.self::UUID.'/guest-overrides/disable',['expectedRevision'=>1,'entries'=>[['id'=>self::UUID]]]],
        ];
        foreach ($routes as [$method,$path,$body]) {
            $this->request($method, $path, $body);
            self::assertNotSame(404, $this->client->getResponse()->getStatusCode(), $path);
            self::assertNotSame(405, $this->client->getResponse()->getStatusCode(), $path);
        }
    }

    /** @return array<string, mixed> */
    private function targetBody(int $revision = 0): array
    {
        return ['expectedRevision'=>$revision,'displayName'=>'Target','connectionId'=>self::UUID,'clusterId'=>self::UUID,'storageId'=>self::UUID,
            'minimumFreeBytes'=>'1024','fixedParallelLimit'=>2,'pbsConnectionId'=>null,'pbsDatastoreId'=>null,'pbsNamespaceId'=>null,'allowedNodeIds'=>[self::UUID]];
    }
    /** @return array<string, mixed> */
    private function policyBody(int $revision = 0): array
    {
        return ['expectedRevision'=>$revision,'displayName'=>'Policy','connectionId'=>self::UUID,'clusterId'=>self::UUID,'targetId'=>self::UUID,
            'priority'=>1,'backupMode'=>'snapshot','compression'=>'zstd','maximumAgeSeconds'=>'60','bytesWrittenThreshold'=>null,'cooldownSeconds'=>null,
            'schedule'=>'collector_cycle','legacyMaxfiles'=>null,'keepAll'=>null,'keepLast'=>1,'keepHourly'=>null,'keepDaily'=>null,'keepWeekly'=>null,'keepMonthly'=>null,'keepYearly'=>null,'retentionExecutionEnabled'=>false,'failureNotificationRecipients'=>[]];
    }
    /** @param array<string, mixed> $body */
    private function request(string $method, string $path, array $body, bool $idempotency = true): void
    {
        $server=['HTTP_X_CSRF_TOKEN'=>$this->csrf]; if($idempotency)$server['HTTP_IDEMPOTENCY_KEY']='test-key';
        $this->client->jsonRequest($method,$path,$body,$server);
    }
    /** @return array<string, mixed> */
    private function json(): array { $v=json_decode((string)$this->client->getResponse()->getContent(),true); self::assertIsArray($v); /** @var array<string, mixed> $v */ return $v; }
}

final class CommandAuthenticator implements HttpRequestAuthenticator
{
    public function __construct(public bool $manage) {}
    public function authenticate(Request $request): SessionResult
    {
        $now=new DateTimeImmutable('2026-07-12T12:00:00Z');
        return new SessionResult(str_repeat('s',16),new AuthenticatedPrincipal(new UserId(str_repeat('u',16)),new NormalizedUsername('admin'),
            [Permission::InventoryRead, ...($this->manage?[Permission::BackupConfigurationManage]:[])]),new OpaqueToken(str_repeat("\x03",32)),new SessionWindow($now,$now,$now->modify('+30 minutes'),$now->modify('+12 hours')));
    }
}

final class CommandStoreFake implements TargetCommandRepository, PolicyCommandRepository, SelectionCommandRepository
{
    public ConfigurationCommandResult $result; public ?BackupTarget $target; public ?BackupPolicy $policy; public bool $fail=false; public int $records=0;
    public function __construct()
    {
        $this->result=new ConfigurationCommandResult(ConfigurationCommandStatus::Applied,2);
        $this->target=new BackupTarget(new BackupTargetId(str_repeat('t',16)),new TargetRevision(1),TargetStatus::Disabled,false,new MinimumFreeBytes('1'),new AllowedNodes([str_repeat('n',16)]),new ConcurrencyPolicy(1),null);
        $this->policy=BackupPolicy::draft(new PolicyId(str_repeat('p',16)),new PolicyRevision(1),new BackupTargetId(str_repeat('t',16)),BackupMode::Snapshot,Compression::Zstd,RetentionPolicy::prune(null,1,null,null,null,null,null),new PolicyPriority(1),new PolicyThresholds(60,null,null),Schedule::CollectorCycle);
    }
    public function find(BackupTargetId $id): ?BackupTarget{return $this->target;}
    public function findPolicy(PolicyId $id): ?BackupPolicy{return $this->policy;}
    public function execute(ConfigurationCommand $command,AuthenticatedPrincipal $principal): ConfigurationCommandResult{if($this->fail)throw new RuntimeException('database secret');return $this->result;}
    public function record(ConfigurationCommand $command,AuthenticatedPrincipal $principal,ConfigurationCommandResult $result): ConfigurationCommandResult{++$this->records;return $result;}
}

final class CommandEvidenceFake implements TargetCandidateEvidenceProvider, TargetExecutorEvidenceProvider, PolicyActivationEvidenceProvider
{
    public int $calls=0;
    private function fresh(): ActivationEvidenceObservation{return new ActivationEvidenceObservation(true,new DateTimeImmutable('now'));}
    public function candidateEvidence(BackupTargetId $id): TargetCandidateEvidence{++$this->calls;$v=$this->fresh();return new TargetCandidateEvidence($v,$v,$v);}
    public function executorEvidence(BackupTargetId $id): ActivationEvidenceObservation{++$this->calls;return $this->fresh();}
    public function policyEvidence(PolicyId $id): PolicyActivationEvidence{++$this->calls;$v=$this->fresh();return new PolicyActivationEvidence(9,new DateTimeImmutable('now'),$v,$v);}
    public function candidateEvidenceBatch(array $ids): array{$result=[];foreach($ids as $id)$result[$id->toHex()]=$this->candidateEvidence($id);return $result;}
    public function executorEvidenceBatch(array $ids): array{$result=[];foreach($ids as $id)$result[$id->toHex()]=$this->executorEvidence($id);return $result;}
    public function policyEvidenceBatch(array $ids): array{$result=[];foreach($ids as $id)$result[bin2hex($id->binary())]=$this->policyEvidence($id);return $result;}
}
