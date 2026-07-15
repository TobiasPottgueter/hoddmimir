<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Configuration\ConfigurationCommand;
use App\Application\Configuration\ConfigurationCommandStatus;
use App\Application\Configuration\ConfigurationCommandType;
use App\Application\Configuration\Connection\Onboarding\OnboardingActivationCommand;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredential;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredentialKind;
use App\Application\Configuration\Connection\Onboarding\OnboardingEndpoint;
use App\Application\Configuration\Connection\Onboarding\OnboardingIssueCode;
use App\Application\Configuration\Connection\Onboarding\OnboardingIssueSeverity;
use App\Application\Configuration\Connection\Onboarding\OnboardingMode;
use App\Application\Configuration\Connection\Onboarding\OnboardingMutationResult;
use App\Application\Configuration\Connection\Onboarding\OnboardingMutationStatus;
use App\Application\Configuration\Connection\Onboarding\OnboardingProduct;
use App\Application\Configuration\Connection\Onboarding\OnboardingTlsMode;
use App\Application\Configuration\Connection\Onboarding\OnboardingVerification;
use App\Application\Configuration\Connection\Onboarding\OnboardingVerificationIssue;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\PlaintextSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\UserId;
use App\Domain\Shared\Clock;
use App\Infrastructure\Persistence\MariaDb\DbalOnboardingActivationRepository;
use App\Infrastructure\Persistence\MariaDb\DbalConnectionAdministration;
use App\Infrastructure\Security\NativeArgon2idPasswordHasher;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use RuntimeException;
use Throwable;

final class OnboardingActivationRepositoryTest extends DatabaseTestCase
{
    private const string USER = 'onboarding-user!';
    private const string CONNECTION = 'onboarding-pve!!';

    public function testActivationIsAtomicIdempotentEncryptedAndWriteOnly(): void
    {
        $this->seedUser();
        $cipher = new OnboardingRecordingCipher();
        $repository = $this->repository($cipher);
        $command = $this->pveCommand(OnboardingMode::Activate, 0, 'activate-pve', 'SCAN-SECRET-ONE', 'BACKUP-SECRET-ONE');
        $sessionId = $this->seedWebSession(self::USER);
        $principal = $this->principal($sessionId);

        $result = $repository->activate($command, $this->passedPveVerification(), $principal);

        self::assertSame(OnboardingMutationStatus::Applied, $result->status);
        self::assertSame(1, $result->revision);
        self::assertSame(1, $this->connection()->fetchOne('SELECT enabled FROM proxmox_connections WHERE id = ?', [self::CONNECTION]));
        self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM proxmox_connection_endpoints WHERE connection_id = ?', [self::CONNECTION]));
        self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM proxmox_endpoint_onboarding_evidence WHERE connection_id = ?', [self::CONNECTION]));
        self::assertSame(2, $this->connection()->fetchOne('SELECT COUNT(*) FROM proxmox_credentials WHERE connection_id = ?', [self::CONNECTION]));
        $verificationHashes = $this->connection()->fetchFirstColumn('SELECT secret_verification_hash FROM proxmox_credentials WHERE connection_id = ?', [self::CONNECTION]);
        self::assertCount(2, $verificationHashes);
        foreach ($verificationHashes as $verificationHash) {
            self::assertIsString($verificationHash);
            self::assertStringStartsWith('$argon2id$', $verificationHash);
        }
        self::assertSame('first_automatic_scan_pending', $this->connection()->fetchOne('SELECT state FROM proxmox_connection_onboarding_state WHERE connection_id = ?', [self::CONNECTION]));
        $envelopeRows = $this->connection()->fetchFirstColumn('SELECT secret_envelope FROM proxmox_credentials WHERE connection_id = ?', [self::CONNECTION]);
        self::assertContainsOnlyString($envelopeRows);
        /** @var list<string> $envelopeRows */
        $envelopes = implode('|', $envelopeRows);
        self::assertStringNotContainsString('SCAN-SECRET-ONE', $envelopes);
        self::assertStringNotContainsString('BACKUP-SECRET-ONE', $envelopes);
        $replayHash = $this->connection()->fetchOne('SELECT secret_replay_hash FROM proxmox_onboarding_commands WHERE actor_user_id = ?', [self::USER]);
        self::assertIsString($replayHash);
        self::assertStringStartsWith('$argon2id$', $replayHash);
        self::assertSame(1, $this->connection()->fetchOne("SELECT COUNT(*) FROM audit_events WHERE subject_type = 'connection' AND subject_id = ?", [self::CONNECTION]));
        self::assertSame($sessionId, $this->connection()->fetchOne("SELECT actor_session_id FROM audit_events WHERE subject_type = 'connection' AND subject_id = ?", [self::CONNECTION]));

        $lostResponseRetry = $this->pveCommand(
            OnboardingMode::Activate,
            0,
            'activate-pve',
            'SCAN-SECRET-ONE',
            'BACKUP-SECRET-ONE',
            connectionId: 'retry-connection',
        );
        self::assertNotSame($command->connectionId, $lostResponseRetry->connectionId);
        $replay = $repository->activate($lostResponseRetry, $this->passedPveVerification(), $principal);
        self::assertSame(OnboardingMutationStatus::Replayed, $replay->status);
        self::assertSame(self::CONNECTION, $replay->connectionId);
        self::assertSame(2, $this->connection()->fetchOne('SELECT COUNT(*) FROM proxmox_credentials WHERE connection_id = ?', [self::CONNECTION]));
        self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM proxmox_onboarding_commands WHERE actor_user_id = ?', [self::USER]));

        $changedSecret = $this->pveCommand(OnboardingMode::Activate, 0, 'activate-pve', 'SCAN-SECRET-DIFFERENT', 'BACKUP-SECRET-ONE');
        self::assertSame(
            OnboardingMutationStatus::Conflict,
            $repository->activate($changedSecret, $this->passedPveVerification(), $principal)->status,
        );
        self::assertSame(2, $cipher->encryptions);
    }

    public function testRotationReplacesBothCredentialsOnlyAfterFullVerificationAndResetsInventoryEvidence(): void
    {
        $this->seedUser();
        $cipher = new OnboardingRecordingCipher();
        $repository = $this->repository($cipher);
        $repository->activate(
            $this->pveCommand(OnboardingMode::Activate, 0, 'activate', 'SCAN-OLD', 'BACKUP-OLD'),
            $this->passedPveVerification(),
            $this->principal(),
        );
        $selectedEndpointId = $this->endpointId();
        $added = $repository->activate(
            $this->pveCommand(OnboardingMode::EndpointAdd, 1, 'add-failover', 'SCAN-OLD', 'BACKUP-OLD', null, 'pve-b.example.test'),
            $this->passedPveVerification(),
            $this->principal(),
        );
        self::assertSame(OnboardingMutationStatus::Applied, $added->status);
        self::assertSame(2, $added->revision);
        self::assertSame(2, $this->connection()->fetchOne('SELECT COUNT(*) FROM proxmox_endpoint_onboarding_evidence WHERE connection_id = ?', [self::CONNECTION]));
        $this->connection()->update('proxmox_connection_onboarding_state', ['state' => 'inventory_verified'], ['connection_id' => self::CONNECTION]);
        $before = $this->credentialEnvelopes();

        $rotation = $repository->activate(
            $this->pveCommand(OnboardingMode::Rotate, 2, 'rotate', 'SCAN-NEW', 'BACKUP-NEW', $selectedEndpointId),
            $this->passedPveVerification(),
            $this->principal(),
        );

        self::assertSame(OnboardingMutationStatus::Applied, $rotation->status);
        self::assertSame(3, $rotation->revision);
        self::assertNotSame($before, $this->credentialEnvelopes());
        $revisions = $this->connection()->fetchFirstColumn('SELECT revision FROM proxmox_credentials WHERE connection_id = ? ORDER BY purpose', [self::CONNECTION]);
        self::assertSame([2, 2], array_map(static function (mixed $value): int {
            if (is_int($value)) {
                return $value;
            }
            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }
            throw new RuntimeException('Unexpected credential revision value.');
        }, $revisions));
        self::assertSame('first_automatic_scan_pending', $this->connection()->fetchOne('SELECT state FROM proxmox_connection_onboarding_state WHERE connection_id = ?', [self::CONNECTION]));

        $losingRace = $repository->activate(
            $this->pveCommand(OnboardingMode::Rotate, 2, 'stale-rotate', 'SCAN-STALE', 'BACKUP-STALE', $selectedEndpointId),
            $this->passedPveVerification(),
            $this->principal(),
        );
        self::assertSame(OnboardingMutationStatus::Conflict, $losingRace->status);
        self::assertSame(3, $losingRace->revision);
        self::assertSame(3, $this->connection()->fetchOne('SELECT revision FROM proxmox_connections WHERE id = ?', [self::CONNECTION]));
    }

    public function testCipherFailureOnSecondCredentialRollsBackEveryActivationRow(): void
    {
        $this->seedUser();
        $repository = $this->repository(new OnboardingRecordingCipher(2));

        try {
            $repository->activate(
                $this->pveCommand(OnboardingMode::Activate, 0, 'failing-activate', 'SCAN-ROLLBACK', 'BACKUP-ROLLBACK'),
                $this->passedPveVerification(),
                $this->principal(),
            );
            self::fail('The injected second-secret failure did not escape.');
        } catch (RuntimeException $failure) {
            self::assertSame('Injected onboarding cipher failure.', $failure->getMessage());
        }

        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM proxmox_connections WHERE id = ?', [self::CONNECTION]));
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM proxmox_connection_endpoints WHERE connection_id = ?', [self::CONNECTION]));
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM proxmox_credentials WHERE connection_id = ?', [self::CONNECTION]));
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM proxmox_onboarding_commands WHERE actor_user_id = ?', [self::USER]));
        self::assertSame(0, $this->connection()->fetchOne("SELECT COUNT(*) FROM audit_events WHERE subject_type = 'connection' AND subject_id = ?", [self::CONNECTION]));
    }

    public function testRotationAgainstAnUnselectedEndpointIsAConflictAndKeepsEveryCredential(): void
    {
        $this->seedUser();
        $repository = $this->repository(new OnboardingRecordingCipher());
        $repository->activate(
            $this->pveCommand(OnboardingMode::Activate, 0, 'activate-endpoint', 'SCAN-OLD', 'BACKUP-OLD'),
            $this->passedPveVerification(),
            $this->principal(),
        );
        $before = $this->credentialEnvelopes();

        $result = $repository->activate(
            $this->pveCommand(OnboardingMode::Rotate, 1, 'rotate-wrong-endpoint', 'SCAN-NEW', 'BACKUP-NEW', 'missing-endpoint'),
            $this->passedPveVerification(),
            $this->principal(),
        );

        self::assertSame(OnboardingMutationStatus::Conflict, $result->status);
        self::assertSame(1, $result->revision);
        self::assertSame($before, $this->credentialEnvelopes());
        self::assertSame('first_automatic_scan_pending', $this->connection()->fetchOne(
            'SELECT state FROM proxmox_connection_onboarding_state WHERE connection_id = ?',
            [self::CONNECTION],
        ));
    }

    public function testDisabledConnectionCanOnlyBeReactivatedByFullyVerifiedOnboardingRotation(): void
    {
        $this->seedUser();
        $repository = $this->repository(new OnboardingRecordingCipher());
        $repository->activate(
            $this->pveCommand(OnboardingMode::Activate, 0, 'activate-reactivation', 'SCAN-OLD', 'BACKUP-OLD'),
            $this->passedPveVerification(),
            $this->principal(),
        );
        $endpointId = $this->endpointId();
        $administration = self::getContainer()->get(DbalConnectionAdministration::class);
        self::assertInstanceOf(DbalConnectionAdministration::class, $administration);

        $disabled = $administration->execute(new ConfigurationCommand(
            ConfigurationCommandType::ConnectionDisable,
            self::CONNECTION,
            1,
            'disable-before-change',
            'disable-corr-id!',
        ), $this->principal());
        self::assertSame(ConfigurationCommandStatus::Applied, $disabled->status);
        self::assertSame(2, $disabled->revision);

        $updated = $administration->execute(new ConfigurationCommand(
            ConfigurationCommandType::EndpointUpdate,
            self::CONNECTION,
            2,
            'update-disabled-endpoint',
            'endpoint-corr-id',
            [
                'endpointId' => $endpointId,
                'host' => 'pve-new.example.test',
                'port' => 8006,
                'priority' => 100,
                'tlsMode' => 'system_ca',
                'customCaPem' => null,
                'sha256Fingerprint' => null,
            ],
        ), $this->principal());
        self::assertSame(ConfigurationCommandStatus::Blocked, $updated->status);
        self::assertSame(['verified_onboarding_required'], $updated->blockers);
        self::assertNull($updated->revision);

        $legacyEnable = $administration->execute(new ConfigurationCommand(
            ConfigurationCommandType::ConnectionEnable,
            self::CONNECTION,
            2,
            'legacy-enable-blocked',
            'enable-corr-id!!',
        ), $this->principal());
        self::assertSame(ConfigurationCommandStatus::Blocked, $legacyEnable->status);
        self::assertSame(['verified_onboarding_required'], $legacyEnable->blockers);

        $this->markInventoryVerified('inventory-run-03');
        $rotation = $repository->activate(
            $this->pveCommand(
                OnboardingMode::Rotate,
                2,
                'verified-reactivation',
                'SCAN-NEW',
                'BACKUP-NEW',
                $endpointId,
            ),
            $this->passedPveVerification(),
            $this->principal(),
        );
        self::assertSame(OnboardingMutationStatus::Applied, $rotation->status);
        self::assertSame(3, $rotation->revision);
        self::assertSame(1, $this->connection()->fetchOne('SELECT enabled FROM proxmox_connections WHERE id = ?', [self::CONNECTION]));
        $this->assertFirstAutomaticScanPending();
        self::assertSame([2, 2], array_map(static function (mixed $value): int {
            if (is_int($value)) {
                return $value;
            }
            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }
            throw new RuntimeException('Unexpected credential revision value.');
        }, $this->connection()->fetchFirstColumn(
            'SELECT revision FROM proxmox_credentials WHERE connection_id = ? ORDER BY purpose',
            [self::CONNECTION],
        )));
    }

    public function testVerifiedEndpointUpdateIsAtomicAndDoesNotRotateCredentials(): void
    {
        $this->seedUser();
        $cipher = new OnboardingRecordingCipher();
        $repository = $this->repository($cipher);
        $repository->activate(
            $this->pveCommand(OnboardingMode::Activate, 0, 'activate-update', 'SCAN-OLD', 'BACKUP-OLD'),
            $this->passedPveVerification(),
            $this->principal(),
        );
        $endpointId = $this->endpointId();
        $beforeCredentials = $this->credentialEnvelopes();
        $beforeHash = $this->connection()->fetchOne('SELECT endpoint_config_hash FROM proxmox_endpoint_onboarding_evidence WHERE endpoint_id = ?', [$endpointId]);

        $result = $repository->activate(
            $this->pveCommand(
                OnboardingMode::EndpointUpdate,
                1,
                'verified-endpoint-update',
                'SCAN-OLD',
                'BACKUP-OLD',
                $endpointId,
                'pve-new.example.test',
            ),
            $this->passedPveVerification(),
            $this->principal(),
        );

        self::assertSame(OnboardingMutationStatus::Applied, $result->status);
        self::assertSame(2, $result->revision);
        self::assertSame('pve-new.example.test', $this->connection()->fetchOne('SELECT host FROM proxmox_connection_endpoints WHERE id = ?', [$endpointId]));
        self::assertNotSame($beforeHash, $this->connection()->fetchOne('SELECT endpoint_config_hash FROM proxmox_endpoint_onboarding_evidence WHERE endpoint_id = ?', [$endpointId]));
        self::assertSame($beforeCredentials, $this->credentialEnvelopes());
        self::assertSame(2, $cipher->encryptions);
    }

    public function testVerifiedEndpointCanBeUpdatedImmediatelyAfterItWasAdded(): void
    {
        $this->seedUser();
        $repository = $this->repository(new OnboardingRecordingCipher());
        $repository->activate(
            $this->pveCommand(OnboardingMode::Activate, 0, 'activate-add-update', 'SCAN-OLD', 'BACKUP-OLD'),
            $this->passedPveVerification(),
            $this->principal(),
        );
        $this->markInventoryVerified('inventory-run-01');
        $added = $repository->activate(
            $this->pveCommand(OnboardingMode::EndpointAdd, 1, 'add-before-update', 'SCAN-OLD', 'BACKUP-OLD', null, 'pve-b.example.test'),
            $this->passedPveVerification(),
            $this->principal(),
        );
        self::assertSame(OnboardingMutationStatus::Applied, $added->status);
        self::assertSame(2, $added->revision);
        $this->assertFirstAutomaticScanPending();
        $endpointId = $this->connection()->fetchOne(
            'SELECT id FROM proxmox_connection_endpoints WHERE connection_id = ? AND host = ?',
            [self::CONNECTION, 'pve-b.example.test'],
        );
        self::assertIsString($endpointId);
        $this->markInventoryVerified('inventory-run-02');

        $updated = $repository->activate(
            $this->pveCommand(
                OnboardingMode::EndpointUpdate,
                2,
                'update-after-add',
                'SCAN-OLD',
                'BACKUP-OLD',
                $endpointId,
                'pve-b-updated.example.test',
            ),
            $this->passedPveVerification(),
            $this->principal(),
        );

        self::assertSame(OnboardingMutationStatus::Applied, $updated->status);
        self::assertSame(3, $updated->revision);
        self::assertSame('pve-b-updated.example.test', $this->connection()->fetchOne(
            'SELECT host FROM proxmox_connection_endpoints WHERE id = ?',
            [$endpointId],
        ));
        $this->assertFirstAutomaticScanPending();
    }

    public function testWebDatabaseRoleCanActivateAddAndUpdateVerifiedEndpointAtomically(): void
    {
        $this->seedUser();
        $parameters = $this->connection()->getParams();
        $password = file_get_contents('/run/secrets/mariadb_web_password');
        self::assertIsString($password);
        $this->connection()->commit();
        $this->connection()->close();
        $web = DriverManager::getConnection(array_replace($parameters, [
            'user' => 'hoddmimir_web',
            'password' => trim($password),
        ]));

        try {
            $cipher = new OnboardingRecordingCipher();
            $hasher = new NativeArgon2idPasswordHasher();
            $clock = new FixedOnboardingClock();
            $ids = new SequentialOnboardingIds();
            $repository = new DbalOnboardingActivationRepository(
                $web,
                $cipher,
                $hasher,
                $clock,
                $ids,
            );
            $activated = $repository->activate(
                $this->pveCommand(OnboardingMode::Activate, 0, 'web-role-activate', 'SCAN-OLD', 'BACKUP-OLD'),
                $this->passedPveVerification(),
                $this->principal(),
            );
            self::assertSame(OnboardingMutationStatus::Applied, $activated->status);
            $added = $repository->activate(
                $this->pveCommand(OnboardingMode::EndpointAdd, 1, 'web-role-add', 'SCAN-OLD', 'BACKUP-OLD', null, 'pve-b.example.test'),
                $this->passedPveVerification(),
                $this->principal(),
            );
            self::assertSame(OnboardingMutationStatus::Applied, $added->status);
            $endpointId = $web->fetchOne(
                'SELECT id FROM proxmox_connection_endpoints WHERE connection_id = ? AND host = ?',
                [self::CONNECTION, 'pve-b.example.test'],
                [\Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::STRING],
            );
            self::assertIsString($endpointId);

            $updated = $repository->activate(
                $this->pveCommand(
                    OnboardingMode::EndpointUpdate,
                    2,
                    'web-role-update',
                    'SCAN-OLD',
                    'BACKUP-OLD',
                    $endpointId,
                    'pve-b-updated.example.test',
                ),
                $this->passedPveVerification(),
                $this->principal(),
            );

            self::assertSame(OnboardingMutationStatus::Applied, $updated->status);
            self::assertSame(3, $updated->revision);
            self::assertSame('pve-b-updated.example.test', $web->fetchOne(
                'SELECT host FROM proxmox_connection_endpoints WHERE id = ?',
                [$endpointId],
                [\Doctrine\DBAL\ParameterType::BINARY],
            ));

            $administration = new DbalConnectionAdministration($web, $hasher, $clock, $ids);
            $disabled = $administration->execute(new ConfigurationCommand(
                ConfigurationCommandType::EndpointDisable,
                self::CONNECTION,
                3,
                'web-role-disable-endpoint',
                str_repeat('d', 16),
                ['endpointId' => $endpointId],
            ), $this->principal());
            self::assertSame(ConfigurationCommandStatus::Applied, $disabled->status);
            self::assertSame(4, $disabled->revision);
            self::assertSame(0, $web->fetchOne(
                'SELECT enabled FROM proxmox_connection_endpoints WHERE id = ?',
                [$endpointId],
                [\Doctrine\DBAL\ParameterType::BINARY],
            ));
            self::assertSame(1, $web->fetchOne(
                'SELECT enabled FROM proxmox_connections WHERE id = ?',
                [self::CONNECTION],
                [\Doctrine\DBAL\ParameterType::BINARY],
            ));

            $remainingEndpointId = $web->fetchOne(
                'SELECT id FROM proxmox_connection_endpoints WHERE connection_id = ? AND enabled = 1',
                [self::CONNECTION],
                [\Doctrine\DBAL\ParameterType::BINARY],
            );
            self::assertIsString($remainingEndpointId);
            $lastEndpoint = $administration->execute(new ConfigurationCommand(
                ConfigurationCommandType::EndpointDisable,
                self::CONNECTION,
                4,
                'web-role-disable-last',
                str_repeat('l', 16),
                ['endpointId' => $remainingEndpointId],
            ), $this->principal());
            self::assertSame(ConfigurationCommandStatus::Blocked, $lastEndpoint->status);
            self::assertSame(['last_enabled_endpoint'], $lastEndpoint->blockers);
            self::assertSame(4, $web->fetchOne(
                'SELECT revision FROM proxmox_connections WHERE id = ?',
                [self::CONNECTION],
                [\Doctrine\DBAL\ParameterType::BINARY],
            ));
            self::assertSame([
                ['outcome' => 'succeeded', 'reason_code' => null],
                ['outcome' => 'denied', 'reason_code' => 'last_enabled_endpoint'],
            ], $web->fetchAllAssociative(
                "SELECT outcome, reason_code FROM audit_events WHERE actor_user_id = ? AND event_type = 'endpoint_disabled' ORDER BY occurred_at, id",
                [self::USER],
                [\Doctrine\DBAL\ParameterType::BINARY],
            ));
        } finally {
            $web->close();
            $cleanup = DriverManager::getConnection($parameters);
            try {
                $cleanup->executeStatement('DELETE FROM audit_events WHERE actor_user_id = ?', [self::USER]);
                $cleanup->executeStatement('DELETE FROM configuration_command_idempotency WHERE actor_user_id = ?', [self::USER]);
                $cleanup->executeStatement('DELETE FROM proxmox_onboarding_commands WHERE actor_user_id = ?', [self::USER]);
                $cleanup->executeStatement('DELETE FROM proxmox_connections WHERE id = ?', [self::CONNECTION]);
                $cleanup->executeStatement('DELETE FROM users WHERE id = ?', [self::USER]);
            } finally {
                $cleanup->close();
            }
        }
    }

    public function testEndpointMutationWithCredentialsDifferentFromActiveStorageIsRejectedWithoutPartialRows(): void
    {
        $this->seedUser();
        $repository = $this->repository(new OnboardingRecordingCipher());
        $repository->activate(
            $this->pveCommand(OnboardingMode::Activate, 0, 'activate-secret-match', 'SCAN-OLD', 'BACKUP-OLD'),
            $this->passedPveVerification(),
            $this->principal(),
        );

        $result = $repository->activate(
            $this->pveCommand(OnboardingMode::EndpointAdd, 1, 'add-secret-mismatch', 'SCAN-DIFFERENT', 'BACKUP-OLD', null, 'pve-b.example.test'),
            $this->passedPveVerification(),
            $this->principal(),
        );

        self::assertSame(OnboardingMutationStatus::Conflict, $result->status);
        self::assertSame(1, $result->revision);
        self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM proxmox_connection_endpoints WHERE connection_id = ?', [self::CONNECTION]));
        self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM proxmox_endpoint_onboarding_evidence WHERE connection_id = ?', [self::CONNECTION]));
        self::assertSame(1, $this->connection()->fetchOne('SELECT revision FROM proxmox_connections WHERE id = ?', [self::CONNECTION]));
    }

    public function testEndpointSecretVerificationCannotUseCredentialRowsFromAnotherConnection(): void
    {
        $this->seedUser();
        $repository = $this->repository(new OnboardingRecordingCipher());
        $repository->activate(
            $this->pveCommand(OnboardingMode::Activate, 0, 'activate-row-scope', 'SCAN-OLD', 'BACKUP-OLD'),
            $this->passedPveVerification(),
            $this->principal(),
        );
        $this->connection()->delete('proxmox_credentials', ['connection_id' => self::CONNECTION]);
        $otherConnection = 'other-connection';
        $now = '2026-07-13 12:00:00.000000';
        $this->connection()->insert('proxmox_connections', [
            'id' => $otherConnection,
            'display_name' => 'Other PVE',
            'product' => 'pve',
            'enabled' => 1,
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $hasher = new NativeArgon2idPasswordHasher();
        foreach ([
            ['collector', 'scan', 'SCAN-OLD'],
            ['backup', 'backup', 'BACKUP-OLD'],
        ] as [$purpose, $tokenName, $secret]) {
            $this->connection()->insert('proxmox_credentials', [
                'id' => random_bytes(16),
                'connection_id' => $otherConnection,
                'purpose' => $purpose,
                'auth_scheme' => 'api_token',
                'principal' => 'hoddmimir@pve',
                'token_name' => $tokenName,
                'secret_envelope' => 'opaque-other-connection-envelope',
                'secret_verification_hash' => $hasher->hash(PlaintextSecret::fromString($secret))->encoded(),
                'envelope_version' => 1,
                'key_id' => 'other-key',
                'revision' => 1,
                'created_at' => $now,
                'rotated_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $result = $repository->activate(
            $this->pveCommand(OnboardingMode::EndpointAdd, 1, 'row-scope-endpoint', 'SCAN-OLD', 'BACKUP-OLD', null, 'pve-b.example.test'),
            $this->passedPveVerification(),
            $this->principal(),
        );

        self::assertSame(OnboardingMutationStatus::Conflict, $result->status);
        self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM proxmox_connection_endpoints WHERE connection_id = ?', [self::CONNECTION]));
        self::assertSame(1, $this->connection()->fetchOne('SELECT revision FROM proxmox_connections WHERE id = ?', [self::CONNECTION]));
    }

    public function testReactivationRejectsEveryEnabledEndpointWithoutMatchingEvidenceUntilItIsVerified(): void
    {
        $this->seedUser();
        $repository = $this->repository(new OnboardingRecordingCipher());
        $repository->activate(
            $this->pveCommand(OnboardingMode::Activate, 0, 'activate-unverified', 'SCAN-OLD', 'BACKUP-OLD'),
            $this->passedPveVerification(),
            $this->principal(),
        );
        $selectedEndpointId = $this->endpointId();
        $administration = self::getContainer()->get(DbalConnectionAdministration::class);
        self::assertInstanceOf(DbalConnectionAdministration::class, $administration);
        self::assertSame(2, $administration->execute(new ConfigurationCommand(
            ConfigurationCommandType::ConnectionDisable,
            self::CONNECTION,
            1,
            'disable-unverified',
            'disable-unverif!',
        ), $this->principal())->revision);
        $legacyEndpointCreate = $administration->execute(new ConfigurationCommand(
            ConfigurationCommandType::EndpointCreate,
            self::CONNECTION,
            2,
            'add-unverified',
            'add-unverified!!',
            [
                'host' => 'pve-unverified.example.test',
                'port' => 8006,
                'priority' => 200,
                'tlsMode' => 'system_ca',
                'customCaPem' => null,
                'sha256Fingerprint' => null,
            ],
        ), $this->principal());
        self::assertSame(ConfigurationCommandStatus::Blocked, $legacyEndpointCreate->status);
        self::assertSame(['verified_onboarding_required'], $legacyEndpointCreate->blockers);
        self::assertNull($legacyEndpointCreate->revision);
        self::assertSame(0, $this->connection()->fetchOne(
            "SELECT COUNT(*) FROM proxmox_connection_endpoints WHERE connection_id = ? AND host = 'pve-unverified.example.test'",
            [self::CONNECTION],
        ));

        // Simulate an endpoint left by a pre-gate deployment. The verified
        // onboarding path must still fail closed until this row has evidence.
        $unverifiedEndpointId = 'unverified-endpt';
        $this->connection()->insert('proxmox_connection_endpoints', [
            'id' => $unverifiedEndpointId,
            'connection_id' => self::CONNECTION,
            'host' => 'pve-unverified.example.test',
            'port' => 8006,
            'priority' => 200,
            'enabled' => 1,
            'tls_mode' => 'system_ca',
            'custom_ca_pem' => null,
            'sha256_fingerprint' => null,
            'created_at' => '2026-07-13 12:00:00.000000',
            'updated_at' => '2026-07-13 12:00:00.000000',
        ]);
        $before = $this->credentialEnvelopes();

        $blocked = $repository->activate(
            $this->pveCommand(OnboardingMode::Rotate, 2, 'rotate-with-unverified', 'SCAN-NEW', 'BACKUP-NEW', $selectedEndpointId),
            $this->passedPveVerification(),
            $this->principal(),
        );
        self::assertSame(OnboardingMutationStatus::Conflict, $blocked->status);
        self::assertSame($before, $this->credentialEnvelopes());
        self::assertSame(0, $this->connection()->fetchOne('SELECT enabled FROM proxmox_connections WHERE id = ?', [self::CONNECTION]));

        $verified = $repository->activate(
            $this->pveCommand(
                OnboardingMode::EndpointUpdate,
                2,
                'verify-added-endpoint',
                'SCAN-OLD',
                'BACKUP-OLD',
                $unverifiedEndpointId,
                'pve-unverified.example.test',
            ),
            $this->passedPveVerification(),
            $this->principal(),
        );
        self::assertSame(OnboardingMutationStatus::Applied, $verified->status);
        self::assertSame(3, $verified->revision);
        $reactivated = $repository->activate(
            $this->pveCommand(OnboardingMode::Rotate, 3, 'rotate-after-verification', 'SCAN-NEW', 'BACKUP-NEW', $selectedEndpointId),
            $this->passedPveVerification(),
            $this->principal(),
        );
        self::assertSame(OnboardingMutationStatus::Applied, $reactivated->status);
        self::assertSame(4, $reactivated->revision);
        self::assertSame(1, $this->connection()->fetchOne('SELECT enabled FROM proxmox_connections WHERE id = ?', [self::CONNECTION]));
    }

    public function testRejectedVerificationPersistsOnlySanitizedReplayAndAudit(): void
    {
        $this->seedUser();
        $repository = $this->repository(new OnboardingRecordingCipher());
        $verification = new OnboardingVerification(false, false, false, false, null, null, [
            new OnboardingVerificationIssue(OnboardingIssueCode::AuthenticationFailed, OnboardingIssueSeverity::Error, OnboardingCredentialKind::Scan),
        ]);
        $command = $this->pveCommand(OnboardingMode::Activate, 0, 'rejected', 'SCAN-REJECTED-SENTINEL', 'BACKUP-REJECTED-SENTINEL');

        $result = $repository->activate($command, $verification, $this->principal());

        self::assertSame(OnboardingMutationStatus::Rejected, $result->status);
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM proxmox_connections WHERE id = ?', [self::CONNECTION]));
        $stored = json_encode($this->connection()->fetchAssociative(
            'SELECT idempotency_key, mode, secret_replay_hash, result_status, verification_json FROM proxmox_onboarding_commands WHERE actor_user_id = ?',
            [self::USER],
        ), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('SCAN-REJECTED-SENTINEL', $stored);
        self::assertStringNotContainsString('BACKUP-REJECTED-SENTINEL', $stored);
        self::assertStringNotContainsString('Authorization', $stored);
        self::assertSame(OnboardingMutationStatus::Rejected, $repository->activate($command, $verification, $this->principal())->status);
    }

    public function testTwoIndependentMariaDbTransactionsProduceOneActivationAndOneDurableConflict(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the onboarding activation race proof.');
        }
        $this->seedUser();
        $parameters = $this->connection()->getParams();
        $directory = sys_get_temp_dir().'/hoddmimir-onboarding-race-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $this->connection()->commit();
        $this->connection()->close();
        $children = [];

        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                self::assertNotSame(-1, $pid);
                if (0 === $pid) {
                    $connection = DriverManager::getConnection($parameters);
                    try {
                        file_put_contents($directory.'/ready-'.$index, '1');
                        $deadline = microtime(true) + 5;
                        while (!is_file($directory.'/go') && microtime(true) < $deadline) {
                            usleep(1_000);
                        }
                        if (!is_file($directory.'/go')) {
                            exit(2);
                        }
                        $repository = new DbalOnboardingActivationRepository(
                            $connection,
                            new OnboardingRecordingCipher(),
                            new NativeArgon2idPasswordHasher(),
                            new FixedOnboardingClock(),
                            new SequentialOnboardingIds(),
                        );
                        $result = $repository->activate(
                            $this->pveCommand(
                                OnboardingMode::Activate,
                                0,
                                'activate-race-'.$index,
                                'SCAN-RACE-'.$index,
                                'BACKUP-RACE-'.$index,
                            ),
                            $this->passedPveVerification(),
                            $this->principal(),
                        );
                        file_put_contents($directory.'/result-'.$index, $result->status->value.':'.($result->revision ?? -1));
                    } catch (Throwable $failure) {
                        file_put_contents($directory.'/result-'.$index, $failure::class.':'.$failure->getMessage());
                        exit(3);
                    } finally {
                        $connection->close();
                    }
                    exit(0);
                }
                $children[] = $pid;
            }

            $deadline = microtime(true) + 5;
            while ((!is_file($directory.'/ready-0') || !is_file($directory.'/ready-1')) && microtime(true) < $deadline) {
                usleep(1_000);
            }
            self::assertFileExists($directory.'/ready-0');
            self::assertFileExists($directory.'/ready-1');
            file_put_contents($directory.'/go', '1');
            $exitStatuses = [];
            foreach ($children as $pid) {
                $status = 0;
                pcntl_waitpid($pid, $status);
                self::assertIsInt($status);
                $exitStatuses[] = $status;
            }

            $results = [
                trim((string) file_get_contents($directory.'/result-0')),
                trim((string) file_get_contents($directory.'/result-1')),
            ];
            sort($results);
            foreach ($exitStatuses as $status) {
                self::assertTrue(pcntl_wifexited($status), implode(' | ', $results));
                self::assertSame(0, pcntl_wexitstatus($status), implode(' | ', $results));
            }
            self::assertSame(['applied:1', 'conflict:1'], $results);

            $verification = DriverManager::getConnection($parameters);
            try {
                self::assertSame(1, $verification->fetchOne('SELECT COUNT(*) FROM proxmox_connections WHERE id = ?', [self::CONNECTION]));
                self::assertSame(2, $verification->fetchOne('SELECT COUNT(*) FROM proxmox_onboarding_commands WHERE actor_user_id = ?', [self::USER]));
                self::assertSame(2, $verification->fetchOne("SELECT COUNT(*) FROM audit_events WHERE subject_type = 'connection' AND subject_id = ?", [self::CONNECTION]));
            } finally {
                $verification->close();
            }
        } finally {
            foreach ($children as $pid) {
                if (posix_kill($pid, 0)) {
                    posix_kill($pid, SIGKILL);
                    $status = 0;
                    pcntl_waitpid($pid, $status);
                }
            }
            $cleanup = DriverManager::getConnection($parameters);
            try {
                $cleanup->executeStatement('DELETE FROM audit_events WHERE actor_user_id = ?', [self::USER]);
                $cleanup->executeStatement('DELETE FROM proxmox_onboarding_commands WHERE actor_user_id = ?', [self::USER]);
                $cleanup->executeStatement('DELETE FROM proxmox_connections WHERE id = ?', [self::CONNECTION]);
                $cleanup->executeStatement('DELETE FROM users WHERE id = ?', [self::USER]);
            } finally {
                $cleanup->close();
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function repository(SecretCipher $cipher): DbalOnboardingActivationRepository
    {
        return new DbalOnboardingActivationRepository(
            $this->connection(),
            $cipher,
            new NativeArgon2idPasswordHasher(),
            new FixedOnboardingClock(),
            new SequentialOnboardingIds(),
        );
    }

    private function passedPveVerification(): OnboardingVerification
    {
        return new OnboardingVerification(true, true, true, true, OnboardingProduct::Pve, '8.4.1', [
            new OnboardingVerificationIssue(
                OnboardingIssueCode::AdditionalReadOnlyPermission,
                OnboardingIssueSeverity::Warning,
                OnboardingCredentialKind::Scan,
                '/nodes',
                'Sys.Log',
            ),
        ]);
    }

    private function pveCommand(
        OnboardingMode $mode,
        int $revision,
        string $key,
        string $scanSecret,
        string $backupSecret,
        ?string $endpointId = null,
        string $host = 'pve.example.test',
        string $connectionId = self::CONNECTION,
    ): OnboardingActivationCommand
    {
        return new OnboardingActivationCommand(
            $mode,
            $connectionId,
            $revision,
            $key,
            'correlation-id01',
            OnboardingProduct::Pve,
            'PVE Production',
            new OnboardingEndpoint($host, 8006, OnboardingTlsMode::SystemCa, null, null),
            [
                new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pve!scan', $scanSecret),
                new OnboardingCredential(OnboardingCredentialKind::Backup, 'hoddmimir@pve!backup', $backupSecret),
            ],
            $endpointId,
        );
    }

    private function endpointId(): string
    {
        $value = $this->connection()->fetchOne(
            'SELECT id FROM proxmox_connection_endpoints WHERE connection_id = ?',
            [self::CONNECTION],
        );
        self::assertIsString($value);

        return $value;
    }

    /** @return array<string, string> */
    private function credentialEnvelopes(): array
    {
        $rows = $this->connection()->fetchAllKeyValue('SELECT purpose, secret_envelope FROM proxmox_credentials WHERE connection_id = ? ORDER BY purpose', [self::CONNECTION]);
        $result = [];
        foreach ($rows as $purpose => $envelope) {
            self::assertIsString($purpose);
            self::assertIsString($envelope);
            $result[$purpose] = $envelope;
        }
        return $result;
    }

    private function markInventoryVerified(string $runId): void
    {
        $this->connection()->update('proxmox_connection_onboarding_state', [
            'state' => 'inventory_verified',
            'inventory_status_changed_at' => '2026-07-13 12:01:00.000000',
            'last_inventory_run_id' => $runId,
        ], ['connection_id' => self::CONNECTION]);
    }

    private function assertFirstAutomaticScanPending(): void
    {
        $state = $this->connection()->fetchAssociative(
            'SELECT state, last_inventory_run_id FROM proxmox_connection_onboarding_state WHERE connection_id = ?',
            [self::CONNECTION],
        );
        self::assertIsArray($state);
        self::assertSame('first_automatic_scan_pending', $state['state']);
        self::assertNull($state['last_inventory_run_id']);
    }

    private function principal(?string $sessionId = null): AuthenticatedPrincipal
    {
        return new AuthenticatedPrincipal(new UserId(self::USER), new NormalizedUsername('onboarding-admin'), [Permission::BackupConfigurationManage], $sessionId);
    }

    private function seedUser(): void
    {
        $now = '2026-07-13 12:00:00.000000';
        $this->connection()->insert('users', [
            'id' => self::USER,
            'username' => 'onboarding-admin',
            'display_name' => 'Onboarding Admin',
            'password_hash' => '$argon2id$dummy',
            'enabled' => 1,
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

final class OnboardingRecordingCipher implements SecretCipher
{
    public int $encryptions = 0;
    /** @var array<string, string> */
    private array $plaintextByEnvelope = [];
    public function __construct(private readonly ?int $failAt = null) {}
    public function encrypt(PlaintextSecret $plaintext, SecretContext $context): EncryptedSecret
    {
        ++$this->encryptions;
        if ($this->failAt === $this->encryptions) {
            throw new RuntimeException('Injected onboarding cipher failure.');
        }
        $encoded = 'encrypted-onboarding-secret-'.$this->encryptions;
        $this->plaintextByEnvelope[$encoded] = $plaintext->consume(static fn (string $secret): string => $secret);
        return EncryptedSecret::fromEncoded($encoded);
    }
    public function decrypt(EncryptedSecret $encrypted, SecretContext $context): PlaintextSecret
    {
        $plaintext = $this->plaintextByEnvelope[$encrypted->encoded()] ?? null;
        if (!is_string($plaintext)) {
            throw new RuntimeException('Unknown onboarding test envelope.');
        }
        return PlaintextSecret::fromString($plaintext);
    }
    public function primaryKeyId(): string { return 'primary'; }
}
final class SequentialOnboardingIds implements SecurityIdentifierGenerator
{
    private int $next = 1;
    public function generate(): string { return pack('J', 0).pack('J', $this->next++); }
}
final readonly class FixedOnboardingClock implements Clock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-07-13T12:00:00.000000Z'); }
}
