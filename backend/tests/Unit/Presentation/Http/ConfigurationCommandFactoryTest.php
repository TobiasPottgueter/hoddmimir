<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Http;

use App\Application\Configuration\ConfigurationCommandType;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Presentation\Http\ConfigurationCommandFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ConfigurationCommandFactoryTest extends TestCase
{
    public function testPolicyRecipientsAreValidatedAndCanonicalized(): void
    {
        $command = (new ConfigurationCommandFactory(new FixedPolicyIdentifierGenerator()))->fromRequest(
            $this->request(['platform@example.test', 'alerts@example.test']),
            ConfigurationCommandType::PolicyCreate,
        );

        self::assertSame(['alerts@example.test', 'platform@example.test'], $command->payload['failureNotificationRecipients']);
    }

    public function testDuplicatePolicyRecipientsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ConfigurationCommandFactory(new FixedPolicyIdentifierGenerator()))->fromRequest(
            $this->request(['alerts@example.test', 'ALERTS@example.test']),
            ConfigurationCommandType::PolicyCreate,
        );
    }

    public function testSelectionEntryIdFromTheWebPayloadIsPreservedForReactivation(): void
    {
        $uuid = '00112233-4455-6677-8899-aabbccddeeff';
        $request = Request::create(
            '/',
            'PUT',
            server: ['HTTP_IDEMPOTENCY_KEY' => 'selection-reactivate'],
            content: json_encode([
                'expectedRevision' => 7,
                'entries' => [[
                    'id' => $uuid,
                    'scope' => 'node',
                    'subjectConnectionId' => $uuid,
                    'subjectClusterId' => $uuid,
                    'nodeId' => $uuid,
                    'selectionValue' => 'include',
                ]],
            ], JSON_THROW_ON_ERROR),
        );

        $command = (new ConfigurationCommandFactory(new FixedPolicyIdentifierGenerator()))->fromRequest(
            $request,
            ConfigurationCommandType::SelectionUpsert,
            $uuid,
        );

        $entries = $command->payload['entries'] ?? null;
        self::assertIsArray($entries);
        $entry = $entries[0] ?? null;
        self::assertIsArray($entry);
        self::assertSame(hex2bin(str_replace('-', '', $uuid)), $entry['id'] ?? null);
        self::assertSame(7, $command->expectedRevision);
    }

    /** @param list<string> $recipients */
    private function request(array $recipients): Request
    {
        return Request::create('/', 'POST', server: ['HTTP_IDEMPOTENCY_KEY' => 'policy-mail'], content: json_encode([
            'expectedRevision'=>0, 'displayName'=>'Policy', 'connectionId'=>'00112233-4455-6677-8899-aabbccddeeff',
            'clusterId'=>'00112233-4455-6677-8899-aabbccddeeff', 'targetId'=>null, 'priority'=>1,
            'backupMode'=>'snapshot', 'compression'=>'zstd', 'maximumAgeSeconds'=>'60', 'bytesWrittenThreshold'=>null,
            'cooldownSeconds'=>null, 'schedule'=>'collector_cycle', 'legacyMaxfiles'=>null, 'keepAll'=>null,
            'keepLast'=>1, 'keepHourly'=>null, 'keepDaily'=>null, 'keepWeekly'=>null, 'keepMonthly'=>null,
            'keepYearly'=>null, 'retentionExecutionEnabled'=>false, 'failureNotificationRecipients'=>$recipients,
        ], JSON_THROW_ON_ERROR));
    }
}

final class FixedPolicyIdentifierGenerator implements SecurityIdentifierGenerator
{
    public function generate(): string
    {
        return str_repeat('p', 16);
    }
}
