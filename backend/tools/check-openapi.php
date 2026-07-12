<?php

declare(strict_types=1);

use App\Application\Inventory\Pbs\PbsInventoryScope;
use App\Application\Inventory\PbsContent\PbsContentScopeType;
use App\Application\Inventory\Pve\PveCoreScope;
use App\Application\Monitoring\MonitoringScopeType;
use App\Kernel;
use Symfony\Component\Routing\RouterInterface;

require dirname(__DIR__).'/vendor/autoload.php';

/** @return never */
function failContract(string $message): void
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

/**
 * @param array<string, mixed> $schema
 * @param list<string> $required
 */
function assertClosedObject(array $schema, array $required, string $name): void
{
    if ('object' !== ($schema['type'] ?? null)
        || false !== ($schema['additionalProperties'] ?? null)
        || $required !== ($schema['required'] ?? null)
        || !is_array($schema['properties'] ?? null)
        || array_keys($schema['properties']) !== $required) {
        failContract('OpenAPI schema '.$name.' drifted from its exact object shape.');
    }
}

/** @param array<string, mixed> $operation */
function responseSchemaReference(array $operation, string $status): ?string
{
    $responses = $operation['responses'] ?? null;
    $response = is_array($responses) ? ($responses[$status] ?? null) : null;
    $content = is_array($response) ? ($response['content'] ?? null) : null;
    $json = is_array($content) ? ($content['application/json'] ?? null) : null;
    $schema = is_array($json) ? ($json['schema'] ?? null) : null;
    $reference = is_array($schema) ? ($schema['$ref'] ?? null) : null;

    return is_string($reference) ? $reference : null;
}

/** @param array<string, mixed> $operation */
function responseReference(array $operation, string $status): ?string
{
    $responses = $operation['responses'] ?? null;
    $response = is_array($responses) ? ($responses[$status] ?? null) : null;
    $reference = is_array($response) ? ($response['$ref'] ?? null) : null;

    return is_string($reference) ? $reference : null;
}

$path = $argv[1] ?? null;
if (!is_string($path) || !is_file($path)) {
    failContract('OpenAPI contract file is missing.');
}
$contents = file_get_contents($path);
if (!is_string($contents)) {
    failContract('OpenAPI contract cannot be read.');
}
try {
    $contract = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    failContract('OpenAPI contract is not valid JSON.');
}
if (!is_array($contract) || '3.1.0' !== ($contract['openapi'] ?? null)
    || [['url' => '/']] !== ($contract['servers'] ?? null)
    || !is_array($contract['paths'] ?? null)) {
    failContract('OpenAPI contract header or server base is invalid.');
}
$components = $contract['components'] ?? null;
if (!is_array($components) || !is_array($components['schemas'] ?? null)) {
    failContract('OpenAPI contract components are invalid.');
}

$expectedResponses = [
    '/api/v1/inventory/overview' => '#/components/schemas/InventoryOverview',
    '/api/v1/inventory/resources' => '#/components/schemas/InventoryResourcePage',
    '/api/v1/collector/status' => '#/components/schemas/CollectorStatus',
    '/api/v1/collector/runs' => '#/components/schemas/CollectorRunPage',
    '/api/v1/collector/scopes' => '#/components/schemas/CollectorScopePage',
];
$operationIds = [];
foreach ($contract['paths'] as $route => $pathItem) {
    if (!is_string($route) || !isset($expectedResponses[$route]) || !is_array($pathItem)
        || ['get'] !== array_keys($pathItem) || !is_array($pathItem['get'])) {
        failContract('OpenAPI paths must exactly match the versioned GET-only router surface.');
    }
    /** @var array<string, mixed> $operation */
    $operation = $pathItem['get'];
    $operationId = $operation['operationId'] ?? null;
    if (!is_string($operationId) || '' === $operationId || isset($operationIds[$operationId])) {
        failContract('OpenAPI operation IDs must be non-empty and unique.');
    }
    $operationIds[$operationId] = true;
    if ($expectedResponses[$route] !== responseSchemaReference($operation, '200')) {
        failContract('OpenAPI success response schema drifted from the runtime operation.');
    }
    if ('/api/v1/inventory/overview' !== $route && '/api/v1/collector/status' !== $route
        && '#/components/responses/InvalidQuery' !== responseReference($operation, '400')) {
        failContract('OpenAPI query operation is missing its stable 400 response.');
    }
}
if (array_keys($contract['paths']) !== array_keys($expectedResponses)) {
    failContract('OpenAPI path order or compatibility baseline changed.');
}

$kernel = new Kernel('test', false);
$kernel->boot();
$router = $kernel->getContainer()->get('router');
if (!$router instanceof RouterInterface) {
    failContract('Symfony router is unavailable for OpenAPI drift validation.');
}
$runtimeRoutes = [];
foreach ($router->getRouteCollection() as $route) {
    $runtimePath = $route->getPath();
    if (!str_starts_with($runtimePath, '/api/v1/')) {
        continue;
    }
    $methods = $route->getMethods();
    sort($methods);
    $runtimeRoutes[$runtimePath] = $methods;
}
$kernel->shutdown();
ksort($runtimeRoutes);
$expectedRuntimeRoutes = array_fill_keys(array_keys($expectedResponses), ['GET']);
ksort($expectedRuntimeRoutes);
if ($expectedRuntimeRoutes !== $runtimeRoutes) {
    failContract('Symfony router and OpenAPI paths or HTTP methods differ.');
}

/** @var array<string, array<string, mixed>> $schemas */
$schemas = $components['schemas'];
$requiredObjects = [
    'PageMetadata' => ['limit', 'count', 'hasMore', 'nextCursor'],
    'InventoryCounts' => [
        'pveConnections', 'pbsConnections', 'pveClusters', 'pveNodes', 'qemuGuests', 'lxcGuests',
        'pveStorages', 'pbsServers', 'pbsDatastores', 'pbsNamespaces', 'pbsBackupGroups', 'pbsSnapshots',
    ],
    'InventoryOverview' => ['generatedAt', 'latestInventoryAt', 'counts'],
    'PveClusterAttributes' => ['topology'],
    'PveNodeAttributes' => ['apiStatus'],
    'PveGuestAttributes' => ['guestType', 'vmid', 'template', 'nodeId', 'nodeName'],
    'PveStorageAttributes' => [
        'storageType', 'supportsBackup', 'shared', 'disabled', 'content', 'pbsServer', 'pbsPort',
        'pbsDatastore', 'pbsNamespace',
    ],
    'PbsServerAttributes' => [
        'nodeName', 'version', 'release', 'repository', 'uptimeSeconds', 'memoryTotalBytes',
        'memoryUsedBytes', 'rootTotalBytes', 'rootUsedBytes', 'rootAvailableBytes',
    ],
    'PbsDatastoreAttributes' => [
        'backendType', 'mountStatus', 'maintenanceMode', 'allowsBackupWrites', 'capacitySemantics',
        'totalBytes', 'usedBytes', 'availableBytes',
    ],
    'PbsNamespaceAttributes' => ['datastoreId', 'namespacePath', 'namespaceDepth', 'parentNamespaceId'],
    'PbsBackupGroupAttributes' => ['namespaceId', 'backupType', 'backupId'],
    'PbsSnapshotAttributes' => ['backupTime', 'protected', 'sizeBytes', 'verificationState'],
    'CollectorScheduleUnconfigured' => ['configured'],
    'CollectorScheduleConfigured' => [
        'configured', 'intervalSeconds', 'nextScanAt', 'lastCycleStartedAt', 'lastCycleFinishedAt',
        'leaseActive', 'leaseExpiresAt',
    ],
    'CollectorHeartbeat' => [
        'status', 'startedAt', 'heartbeatAt', 'expiresAt', 'fresh', 'currentActivity',
        'nextActionAt', 'buildVersion',
    ],
    'CollectorStatus' => ['generatedAt', 'schedule', 'heartbeat'],
    'CollectorRun' => [
        'id', 'connectionId', 'connectionName', 'product', 'status', 'authoritative', 'startedAt',
        'finishedAt', 'appliedAt', 'nodesSeen', 'guestsSeen', 'storagesSeen', 'errorCode',
    ],
    'CollectorScope' => ['runId', 'scopeType', 'scopeKey', 'status', 'observedAt', 'errorCode'],
    'InventoryResourcePage' => ['items', 'page'],
    'CollectorRunPage' => ['items', 'page'],
    'CollectorScopePage' => ['items', 'page'],
    'ApiError' => ['error'],
];
foreach ($requiredObjects as $name => $required) {
    $schema = $schemas[$name] ?? null;
    if (!is_array($schema)) {
        failContract('OpenAPI is missing exact schema '.$name.'.');
    }
    assertClosedObject($schema, $required, $name);
}

$resourceKinds = $schemas['InventoryResourceKind']['enum'] ?? null;
$expectedKinds = [
    'pve_cluster', 'pve_node', 'pve_guest', 'pve_storage', 'pbs_server', 'pbs_datastore',
    'pbs_namespace', 'pbs_backup_group', 'pbs_snapshot',
];
$inventoryResource = $schemas['InventoryResource'] ?? null;
$discriminator = is_array($inventoryResource) ? ($inventoryResource['discriminator'] ?? null) : null;
$alternatives = is_array($inventoryResource) ? ($inventoryResource['oneOf'] ?? null) : null;
if ($expectedKinds !== $resourceKinds
    || !is_array($discriminator)
    || 'kind' !== ($discriminator['propertyName'] ?? null)
    || !is_array($alternatives)
    || count($alternatives) !== count($expectedKinds)
    || '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
        !== ($schemas['CanonicalUuid']['pattern'] ?? null)) {
    failContract('OpenAPI resource discriminator or canonical identifier contract drifted.');
}

$expectedScopeTypes = array_map(
    static fn (\BackedEnum $scope): string => (string) $scope->value,
    [
        ...PveCoreScope::cases(),
        ...PbsInventoryScope::cases(),
        ...PbsContentScopeType::cases(),
        ...MonitoringScopeType::cases(),
    ],
);
$collectorScope = $schemas['CollectorScope'] ?? null;
$scopeProperties = is_array($collectorScope) ? ($collectorScope['properties'] ?? null) : null;
$scopeType = is_array($scopeProperties) ? ($scopeProperties['scopeType'] ?? null) : null;
$publishedScopeTypes = is_array($scopeType) ? ($scopeType['enum'] ?? null) : null;
if ($expectedScopeTypes !== $publishedScopeTypes) {
    failContract('OpenAPI collector scope types drifted from the persisted application scope types.');
}

foreach (['owner_auth_id', 'encryption_fingerprint', 'verification_upid', 'files_json', 'comment'] as $forbidden) {
    if (str_contains($contents, $forbidden)) {
        failContract('OpenAPI exposes a forbidden PBS content field.');
    }
}

fwrite(STDOUT, "OpenAPI v1 router and exact schema contract passed.\n");
