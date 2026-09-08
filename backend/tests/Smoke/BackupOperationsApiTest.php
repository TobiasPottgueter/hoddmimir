<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Application\Backup\Operations\BackupOperationCommand;
use App\Application\Backup\Operations\BackupOperationCommandRepository;
use App\Application\Backup\Operations\BackupOperationCommandResult;
use App\Application\Backup\Operations\BackupOperationCommandStatus;
use App\Application\Backup\Operations\BackupRequestState;
use App\Application\Backup\Operations\BackupRunState;
use App\Application\Backup\Operations\OperationsPage;
use App\Application\Backup\Operations\OperationsReadModel;
use App\Application\Backup\Operations\OperationsCollectorSchedule;
use App\Application\Backup\Operations\OperationsDashboard;
use App\Application\Backup\Operations\OperationsNotificationHealth;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Security\Audit\AuditEventStore;
use App\Application\Security\Audit\SecurityAuditEvent;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\AuthenticationFailed;
use App\Application\Security\Auth\OpaqueToken;
use App\Application\Security\Auth\SessionResult;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\SessionWindow;
use App\Domain\Security\UserId;
use App\Infrastructure\Persistence\MariaDb\DbalBackupOperationCommandRepository;
use App\Infrastructure\Persistence\MariaDb\DbalLocalAuthStore;
use App\Infrastructure\Persistence\MariaDb\DbalOperationsReadModel;
use App\Presentation\Http\Auth\HttpRequestAuthenticator;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class BackupOperationsApiTest extends WebTestCase
{
    private KernelBrowser $client; private OperationsAuthenticator $auth; private OperationCommandFake $commands; private OperationsAuditStore $audit;
    protected function setUp(): void
    {
        $this->client=self::createClient(); $this->client->disableReboot();
        self::getContainer()->set(DbalOperationsReadModel::class,new OperationsReadFake());
        $this->commands=new OperationCommandFake(); self::getContainer()->set(DbalBackupOperationCommandRepository::class,$this->commands);
        $this->audit = new OperationsAuditStore(); self::getContainer()->set(DbalLocalAuthStore::class, $this->audit);
        $this->auth=new OperationsAuthenticator(); self::getContainer()->set(HttpRequestAuthenticator::class,$this->auth);
    }
    public function testReadsAreSessionAndInventoryPermissionProtected(): void
    {
        $this->auth->authenticated=false; $this->client->request('GET','/api/v1/operations/dashboard'); self::assertResponseStatusCodeSame(401);
        $this->auth->authenticated=true; $this->auth->permissions=[]; $this->client->request('GET','/api/v1/operations/queue'); self::assertResponseStatusCodeSame(403);
        $this->auth->permissions=[Permission::InventoryRead]; $this->client->request('GET','/api/v1/operations/queue?limit=1'); self::assertResponseIsSuccessful();
        $queue = $this->json()['items'] ?? null;
        self::assertIsArray($queue);
        $first = $queue[0] ?? null;
        self::assertIsArray($first);
        self::assertSame(self::POLICY_ID, $first['policyId'] ?? null);
        self::assertSame(self::TARGET_ID, $first['targetId'] ?? null);
        $this->client->request('GET','/api/v1/operations/runs/'.self::UUID); self::assertResponseIsSuccessful();
        $this->client->request('GET','/api/v1/operations/runs/missing'); self::assertResponseStatusCodeSame(404);
    }
    public function testQueueHistoryIsProtectedBoundedAndValidatesFilters(): void
    {
        $metrics = $this->createMock(\App\Application\Backup\Metrics\QueueMetricStore::class);
        $metrics->expects(self::exactly(2))->method('history')->willReturn([]);
        self::getContainer()->set(\App\Infrastructure\Persistence\MariaDb\DbalQueueMetricStore::class, $metrics);
        $this->auth->authenticated = false;
        $this->client->request('GET', '/api/v1/operations/queue/history');
        self::assertResponseStatusCodeSame(401);
        $this->auth->authenticated = true;
        $this->auth->permissions = [];
        $this->client->request('GET', '/api/v1/operations/queue/history');
        self::assertResponseStatusCodeSame(403);
        $this->auth->permissions = [Permission::InventoryRead];
        foreach (['hours=25', 'hours=024', 'targetId=invalid', 'unexpected=yes'] as $query) {
            $this->client->request('GET', '/api/v1/operations/queue/history?'.$query);
            self::assertResponseStatusCodeSame(400);
        }
        $this->client->request('GET', '/api/v1/operations/queue/history');
        self::assertResponseIsSuccessful();
        self::assertSame(['bucketSeconds'=>120, 'retentionDays'=>30, 'items'=>[]], $this->json());
        $this->client->request('GET', '/api/v1/operations/queue/history?hours=720&targetId='.self::TARGET_ID);
        self::assertResponseIsSuccessful();
        self::assertSame(3600, $this->json()['bucketSeconds']);
    }

    public function testPbsTasksAreProtectedAndValidateTheReadSurface(): void
    {
        $tasks = $this->createStub(\App\Application\Monitoring\PbsTaskReadModel::class);
        $tasks->method('tasks')->willReturn(['items' => [], 'total' => 0]);
        $tasks->method('detail')->willReturn(null);
        self::getContainer()->set(\App\Infrastructure\Persistence\MariaDb\DbalPbsTaskReadModel::class, $tasks);
        $this->auth->authenticated = false;
        $this->client->request('GET', '/api/v1/operations/pbs-tasks'); self::assertResponseStatusCodeSame(401);
        $this->auth->authenticated = true; $this->auth->permissions = [];
        $this->client->request('GET', '/api/v1/operations/pbs-tasks'); self::assertResponseStatusCodeSame(403);
        $this->auth->permissions = [Permission::InventoryRead];
        foreach (['offset=-1', 'offset=10000000', 'connectionId=wrong', 'scan=now'] as $query) {
            $this->client->request('GET', '/api/v1/operations/pbs-tasks?'.$query); self::assertResponseStatusCodeSame(400);
        }
        $this->client->request('GET', '/api/v1/operations/pbs-tasks'); self::assertResponseIsSuccessful();
        self::assertSame(['items' => [], 'total' => 0], $this->json());
        $this->client->request('GET', '/api/v1/operations/pbs-tasks?connectionId='.self::UUID.'&offset=50'); self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/operations/pbs-tasks/'.self::UUID); self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/v1/operations/pbs-tasks/invalid'); self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/api/v1/operations/pbs-tasks/'.self::UUID.'?download=true'); self::assertResponseStatusCodeSame(400);
    }

    public function testAllReadRoutesAndClosedQueryErrorsAreMapped(): void
    {
        $this->auth->permissions=[Permission::InventoryRead,Permission::AuditRead];
        foreach ([
            '/api/v1/operations/dashboard', '/api/v1/operations/queue?state=pending',
            '/api/v1/operations/runs?state=succeeded', '/api/v1/operations/requests/'.self::UUID.'/events?limit=1',
            '/api/v1/operations/runs/'.self::UUID.'/events?limit=1', '/api/v1/operations/runs/'.self::UUID.'/logs?limit=1',
            '/api/v1/operations/notifications/health', '/api/v1/operations/notifications?kind=recovery&limit=1',
        ] as $path) { $this->client->request('GET',$path); self::assertResponseIsSuccessful(); }
        foreach ([
            '/api/v1/operations/queue?unknown=1', '/api/v1/operations/queue?state=invalid',
            '/api/v1/operations/runs?state=invalid', '/api/v1/operations/runs?limit=0',
            '/api/v1/operations/runs?cursor=invalid', '/api/v1/operations/runs/'.self::UUID.'?expand=secret',
            '/api/v1/operations/notifications?kind=invalid',
        ] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(400, $path);
        }

        $read = self::getContainer()->get(DbalOperationsReadModel::class); self::assertInstanceOf(OperationsReadFake::class,$read); $read->fail=true;
        $this->client->request('GET','/api/v1/operations/dashboard'); self::assertResponseStatusCodeSame(503);
    }
    public function testCommandsRequireCsrfOperationsPermissionAndReturnTypedOutcomes(): void
    {
        $body=['guestId'=>self::UUID,'policyId'=>self::UUID,'expectedRevision'=>1];
        $this->client->jsonRequest('POST','/api/v1/operations/requests',$body); self::assertResponseStatusCodeSame(403);
        $this->auth->permissions=[Permission::InventoryRead]; $this->request('/api/v1/operations/requests',$body); self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->audit->events);
        self::assertSame('manual_backup_requested', $this->audit->events[0]->type->value);
        self::assertSame('denied', $this->audit->events[0]->outcome->value);
        self::assertSame(str_repeat('s', 16), $this->audit->events[0]->actorSessionId);
        $this->auth->permissions=[Permission::InventoryRead,Permission::BackupOperationsManage]; $this->request('/api/v1/operations/requests',$body); self::assertResponseStatusCodeSame(201); self::assertSame('applied',$this->json()['status'] ?? null);
        $this->commands->result=new BackupOperationCommandResult(BackupOperationCommandStatus::Conflict,7); $this->request('/api/v1/operations/requests/'.self::UUID.'/cancel',['expectedRevision'=>1]); self::assertResponseStatusCodeSame(409);
        $this->commands->result=new BackupOperationCommandResult(BackupOperationCommandStatus::Blocked,null,'request_terminal'); $this->request('/api/v1/operations/requests/'.self::UUID.'/cancel',['expectedRevision'=>1]); self::assertResponseStatusCodeSame(422);
        $this->commands->result=new BackupOperationCommandResult(BackupOperationCommandStatus::Replayed,2); $this->request('/api/v1/operations/requests',$body); self::assertResponseIsSuccessful(); self::assertSame('replayed',$this->json()['status'] ?? null);
        $this->request('/api/v1/operations/requests',['guestId'=>'invalid','policyId'=>self::UUID,'expectedRevision'=>1]); self::assertResponseStatusCodeSame(400);
        $this->request('/api/v1/operations/requests/'.self::UUID.'/cancel',['expectedRevision'=>-1]); self::assertResponseStatusCodeSame(400);
        $this->commands->fail=true; $this->request('/api/v1/operations/requests',$body); self::assertResponseStatusCodeSame(503);
        $this->request('/api/v1/operations/requests/'.self::UUID.'/cancel',['expectedRevision'=>1]); self::assertResponseStatusCodeSame(503);
    }
    private const string UUID='00112233-4455-6677-8899-aabbccddeeff';
    private const string POLICY_ID='10112233-4455-6677-8899-aabbccddeeff';
    private const string TARGET_ID='20112233-4455-6677-8899-aabbccddeeff';
    /** @param array<string,mixed> $body */
    private function request(string $path,array $body): void { $csrf=rtrim(strtr(base64_encode(str_repeat("\x03",32)),'+/','-_'),'='); $this->client->jsonRequest('POST',$path,$body,['HTTP_X_CSRF_TOKEN'=>$csrf,'HTTP_IDEMPOTENCY_KEY'=>'operation-1']); }
    /** @return array<string,mixed> */ private function json(): array { $v=json_decode((string)$this->client->getResponse()->getContent(),true); self::assertIsArray($v); /** @var array<string,mixed> $v */ return $v; }
}

final class OperationsAuthenticator implements HttpRequestAuthenticator
{
    public bool $authenticated=true; /** @var list<Permission> */ public array $permissions=[Permission::InventoryRead,Permission::BackupOperationsManage];
    public function authenticate(Request $request): SessionResult { if(!$this->authenticated)throw new AuthenticationFailed(); $now=new DateTimeImmutable('2026-07-13T00:00:00Z'); return new SessionResult(str_repeat('s',16),new AuthenticatedPrincipal(new UserId(str_repeat('u',16)),new NormalizedUsername('operator'),$this->permissions,str_repeat('s',16)),new OpaqueToken(str_repeat("\x03",32)),new SessionWindow($now,$now,$now->modify('+30 minutes'),$now->modify('+12 hours'))); }
}
final class OperationsAuditStore implements AuditEventStore
{
    /** @var list<SecurityAuditEvent> */ public array $events=[];
    public function append(SecurityAuditEvent $event): void { $this->events[]=$event; }
}
final class OperationCommandFake implements BackupOperationCommandRepository
{
    public BackupOperationCommandResult $result; public bool $fail=false; public function __construct(){ $this->result=new BackupOperationCommandResult(BackupOperationCommandStatus::Applied,1); }
    public function execute(BackupOperationCommand $command,AuthenticatedPrincipal $principal): BackupOperationCommandResult{if($this->fail)throw new \RuntimeException('closed'); return $this->result;}
}
final class OperationsReadFake implements OperationsReadModel
{
    public bool $fail=false;
    public function dashboard(bool $includeAudit): OperationsDashboard{if($this->fail)throw new \RuntimeException('closed'); return new OperationsDashboard(['collector'=>null,'backup'=>null],new OperationsCollectorSchedule('2026-07-13T00:02:00.000000Z',null,null,null),['systems'=>0,'nodes'=>0,'guests'=>0,'targets'=>0,'policies'=>0],[],['manual'=>0,'never_backed_up'=>0,'max_age'=>0,'bytes_written'=>0],[],null,null,0,0,0,$this->notificationHealth(),[],$includeAudit);}
    public function queue(PageRequest $page,?BackupRequestState $state): OperationsPage{return new OperationsPage($page,[['policyId'=>'10112233-4455-6677-8899-aabbccddeeff','targetId'=>'20112233-4455-6677-8899-aabbccddeeff']],null);}
    public function runs(PageRequest $page,?BackupRunState $state): OperationsPage{return new OperationsPage($page,[],null);}
    public function run(string $id): ?array{return 'missing' === $id ? null : ['id'=>$id];}
    public function requestEvents(string $requestId,PageRequest $page): OperationsPage{return new OperationsPage($page,[],null);}
    public function runEvents(string $runId,PageRequest $page): OperationsPage{return new OperationsPage($page,[],null);}
    public function runLogs(string $runId,PageRequest $page): OperationsPage{return new OperationsPage($page,[],null);}
    public function notifications(PageRequest $page,?string $kind): OperationsPage{if('invalid'===$kind)throw new \InvalidArgumentException(); return new OperationsPage($page,[],null);}
    public function notificationHealth(): OperationsNotificationHealth{return new OperationsNotificationHealth(['pending'=>0,'claimed'=>0,'sent'=>0],null,null,null);}
}
