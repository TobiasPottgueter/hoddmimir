<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Configuration\Connection\Onboarding;

use App\Application\Configuration\Connection\Onboarding\OnboardingGuidanceProvider;
use App\Application\Configuration\Connection\Onboarding\OnboardingProduct;
use PHPUnit\Framework\TestCase;

final class OnboardingGuidanceProviderTest extends TestCase
{
    public function testPveGuidanceIsAnOrderedSecretFreeSetOfInspectionAndSingleCliCommands(): void
    {
        $value = (new OnboardingGuidanceProvider())->guidance(OnboardingProduct::Pve)->toArray();

        self::assertSame('pve', $value['product']);
        self::assertCount(16, $value['commands']);
        self::assertNotEmpty($value['warnings']);
        self::assertSame('pve-read-users', $value['commands'][0]['id']);
        self::assertSame('pve-token-backup-acl', $value['commands'][14]['id']);
        $this->assertSafeCommands($value['commands']);
        self::assertStringContainsString('HoddmimirScan', $value['commands'][7]['command']);
        self::assertStringContainsString('Datastore.AllocateSpace VM.Backup', $value['commands'][8]['command']);
        self::assertStringContainsString('--privsep 1', $value['commands'][11]['command']);
        self::assertStringContainsString('--propagate 1', $value['commands'][14]['command']);
        self::assertStringContainsString('acl modify /nodes', $value['commands'][15]['command']);
        self::assertStringContainsString('--roles HoddmimirScan', $value['commands'][15]['command']);
    }

    public function testPbsGuidanceUsesOnlyBuiltInRolesForBothUserAndToken(): void
    {
        $value = (new OnboardingGuidanceProvider())->guidance(OnboardingProduct::Pbs)->toArray();

        self::assertSame('pbs', $value['product']);
        self::assertCount(11, $value['commands']);
        $this->assertSafeCommands($value['commands']);
        $joined = implode("\n", array_column($value['commands'], 'command'));
        self::assertStringContainsString('/system Audit', $joined);
        self::assertStringContainsString('/datastore DatastoreAudit', $joined);
        self::assertStringContainsString('/remote RemoteAudit', $joined);
        self::assertStringNotContainsString('DatastoreBackup', $joined);
        self::assertStringNotContainsString('HoddmimirScan', $joined);
        self::assertStringNotContainsString('HoddmimirBackup', $joined);
    }

    /** @param list<array{id: string, command: string, purpose: string, mutatesRemote: bool, containsSecret: false}> $commands */
    private function assertSafeCommands(array $commands): void
    {
        self::assertSame(count($commands), count(array_unique(array_column($commands, 'id'))));
        foreach ($commands as $command) {
            self::assertStringNotContainsString("\n", $command['command']);
            self::assertStringNotContainsString('curl', $command['command']);
            self::assertStringNotContainsString('| sh', $command['command']);
            self::assertStringNotContainsString('TOKEN-SECRET', $command['command']);
            self::assertNotSame('', $command['purpose']);
        }
    }
}
