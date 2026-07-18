<?php

declare(strict_types=1);

namespace App\Tests\Contract\Http;

use PHPUnit\Framework\TestCase;

final class ExecutorPermissionEvidenceOpenApiContractTest extends TestCase
{
    public function testAuthenticatedPaginatedGuestEvidenceContractIsClosedAndGeneratedFromOpenApi(): void
    {
        $raw = file_get_contents(dirname(__DIR__, 4).'/docs/openapi-v1.json');
        self::assertIsString($raw);
        $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        /** @var array<string, mixed> $document */
        self::assertSame([['sessionCookie' => []]], $document['security'] ?? null);

        $paths = $this->object($document['paths'] ?? null);
        $path = $this->object($paths['/api/v1/executor-permission-evidence'] ?? null);
        $operation = $this->object($path['get'] ?? null);
        self::assertSame('listExecutorPermissionEvidence', $operation['operationId'] ?? null);
        $parameters = $operation['parameters'] ?? null;
        self::assertIsArray($parameters);
        $parameterReferences = [];
        foreach ($parameters as $parameter) {
            $parameterReferences[] = $this->object($parameter)['$ref'] ?? null;
        }
        self::assertSame([
            '#/components/parameters/Limit',
            '#/components/parameters/Cursor',
            '#/components/parameters/ConnectionId',
            '#/components/parameters/ClusterId',
            '#/components/parameters/TargetId',
            '#/components/parameters/NodeId',
            '#/components/parameters/GuestId',
        ], $parameterReferences);
        $responses = $this->object($operation['responses'] ?? null);
        $success = $this->object($responses[200] ?? null);
        $content = $this->object($success['content'] ?? null);
        $json = $this->object($content['application/json'] ?? null);
        $responseSchema = $this->object($json['schema'] ?? null);
        self::assertSame(
            '#/components/schemas/ExecutorPermissionEvidencePage',
            $responseSchema['$ref'] ?? null,
        );
        self::assertSame([200, 400, 401, 403, 503], array_keys($responses));

        $components = $this->object($document['components'] ?? null);
        $schemas = $this->object($components['schemas'] ?? null);
        $schema = $this->object($schemas['ExecutorPermissionEvidence'] ?? null);
        self::assertFalse($schema['additionalProperties'] ?? null);
        $fields = [
            'id', 'connectionId', 'clusterId', 'targetId', 'nodeId', 'storageId', 'guestId',
            'evidenceSetRevision', 'endpointId', 'connectionRevision', 'backupCredentialRevision',
            'scanCredentialRevision', 'observedAt', 'freshness', 'vmBackupAuthorized',
            'datastoreAllocateAuthorized', 'authorized', 'missingPermissions',
        ];
        self::assertSame($fields, $schema['required'] ?? null);
        $properties = $this->object($schema['properties'] ?? null);
        self::assertSame($fields, array_keys($properties));
        self::assertSame([
            'type' => 'string',
            'pattern' => '^(?:0|[1-9][0-9]{0,19})$',
            'x-maximum' => '18446744073709551615',
        ], $properties['evidenceSetRevision'] ?? null);
        $permissionName = $this->object($schemas['ExecutorPermissionName'] ?? null);
        self::assertSame(
            ['VM.Backup', 'Datastore.AllocateSpace'],
            $permissionName['enum'] ?? null,
        );
    }

    /** @return array<string|int, mixed> */
    private function object(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
