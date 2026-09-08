<?php

declare(strict_types=1);

namespace App\Tests\Contract\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsUpid;
use App\Infrastructure\Proxmox\Pbs\PbsApiEnvelope;
use App\Infrastructure\Proxmox\Pbs\PbsApiTransport;
use App\Infrastructure\Proxmox\Pbs\PbsHttpTasksAndJobsClient;
use App\Infrastructure\Proxmox\Pbs\PbsJobListReader;
use App\Infrastructure\Proxmox\Pbs\PbsPermissionReader;
use App\Infrastructure\Proxmox\Pbs\PbsRequest;
use App\Infrastructure\Proxmox\Pbs\PbsTaskPageReader;
use PHPUnit\Framework\TestCase;

final class PbsTaskInspectionFixtureContractTest extends TestCase
{
    public function testPbsThreeAndFourInspectionContracts(): void
    {
        foreach ([3, 4] as $version) {
            $transport = new class($version) implements PbsApiTransport {
                public function __construct(private int $version) {}
                public function get(PbsRequest $request): PbsApiEnvelope
                {
                    $path = dirname(__DIR__, 3).'/Fixtures/Proxmox/Pbs/'.$this->version.'/task-'.$request->pathSegments[4].'.json';
                    $text = file_get_contents($path);
                    TestCase::assertIsString($text);
                    $body = json_decode($text, false, 32, JSON_THROW_ON_ERROR);
                    TestCase::assertInstanceOf(\stdClass::class, $body);
                    return new PbsApiEnvelope($body->data, null);
                }
            };
            $client = new PbsHttpTasksAndJobsClient($transport, new PbsPermissionReader(), new PbsJobListReader(), new PbsTaskPageReader());
            $inspection = $client->inspect(new PbsUpid('UPID:pbs:00000001:00000002:00000003:65000000:verify:store:collector@pbs!audit:'));
            self::assertSame('stopped', $inspection->status);
            self::assertSame('OK', $inspection->exitStatus);
            self::assertSame(3 === $version ? null : 0x65000000 + 10, $inspection->endTime);
            self::assertCount(2, $inspection->lines);
            self::assertNull($inspection->statusFailure);
            self::assertNull($inspection->logFailure);
        }
    }
}
