<?php

declare(strict_types=1);

use App\Application\Inventory\Pbs\PbsInventoryScope;
use App\Application\Inventory\PbsContent\PbsContentScopeType;
use App\Application\Inventory\Pve\PveCoreScope;
use App\Application\Configuration\Connection\Onboarding\OnboardingIssueCode;
use App\Application\Monitoring\MonitoringScopeType;
use App\Application\Configuration\Policy\PolicyActivationBlockerCode;
use App\Application\Target\ReadModel\BackupTargetBlockerCode;
use App\Application\Target\ReadModel\BackupTargetCapacityStatus;
use App\Application\Target\ReadModel\BackupTargetExecutorStatus;
use App\Domain\Target\TargetActivationBlocker;
use App\Application\Target\ReadModel\PbsEndpointMatchStatus;
use App\Domain\Shared\UInt64Decimal;
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
 * @param list<string> $optional
 */
function assertClosedObject(array $schema, array $required, string $name, array $optional = []): void
{
    if ('object' !== ($schema['type'] ?? null)
        || false !== ($schema['additionalProperties'] ?? null)
        || $required !== ($schema['required'] ?? null)
        || !is_array($schema['properties'] ?? null)
        || array_keys($schema['properties']) !== [...$optional, ...$required]) {
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

/**
 * @param array<string, mixed> $source
 * @param list<string>         $keys
 */
function nestedValue(array $source, array $keys): mixed
{
    $value = $source;
    foreach ($keys as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return null;
        }
        $value = $value[$key];
    }

    return $value;
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
    '/api/v1/backup-target-candidates' => '#/components/schemas/BackupTargetCandidatePage',
    '/api/v1/executor-permission-evidence' => '#/components/schemas/ExecutorPermissionEvidencePage',
    '/api/v1/backup-targets' => '#/components/schemas/ConfiguredBackupTargetPage',
    '/api/v1/policies' => '#/components/schemas/PolicyPage',
    '/api/v1/policies/{id}/selection' => '#/components/schemas/PolicySelectionPage',
    '/api/v1/shadow/evaluations' => '#/components/schemas/ShadowEvaluationPage',
    '/api/v1/shadow/decisions' => '#/components/schemas/ShadowDecisionPage',
    '/api/v1/shadow/decisions/{id}' => '#/components/schemas/ShadowDecisionDetail',
    '/api/v1/operations/dashboard' => '#/components/schemas/OperationsDashboard',
    '/api/v1/operations/queue' => '#/components/schemas/BackupRequestPage',
    '/api/v1/operations/pbs-tasks' => '#/components/schemas/PbsObservedTaskPage',
    '/api/v1/operations/pbs-tasks/{id}' => '#/components/schemas/PbsObservedTaskDetail',
    '/api/v1/operations/queue/history' => '#/components/schemas/QueueMetricHistory',
    '/api/v1/operations/requests/{id}/events' => '#/components/schemas/BackupEventPage',
    '/api/v1/operations/runs' => '#/components/schemas/BackupRunPage',
    '/api/v1/operations/runs/{id}' => '#/components/schemas/BackupRun',
    '/api/v1/operations/runs/{id}/events' => '#/components/schemas/BackupEventPage',
    '/api/v1/operations/runs/{id}/logs' => '#/components/schemas/BackupLogPage',
    '/api/v1/operations/notifications' => '#/components/schemas/BackupNotificationPage',
    '/api/v1/operations/notifications/health' => '#/components/schemas/BackupNotificationHealth',
    '/api/v1/collector/status' => '#/components/schemas/CollectorStatus',
    '/api/v1/collector/runs' => '#/components/schemas/CollectorRunPage',
    '/api/v1/collector/scopes' => '#/components/schemas/CollectorScopePage',
    '/api/v1/admin/roles' => '#/components/schemas/AdministrationRolePage',
    '/api/v1/admin/audit-events' => '#/components/schemas/AdministrationAuditEventPage',
    '/api/v1/admin/audit-events/{id}' => '#/components/schemas/AdministrationAuditEvent',
    '/api/v1/admin/health' => '#/components/schemas/AdministrationHealthReport',
];
$authMethods = [
    '/api/v1/auth/login' => 'post',
    '/api/v1/auth/session' => 'get',
    '/api/v1/auth/logout' => 'post',
];
$commandMethods = [
    '/api/v1/backup-targets' => ['get', 'post'],
    '/api/v1/backup-targets/{id}' => ['put'],
    '/api/v1/backup-targets/{id}/enable' => ['post'],
    '/api/v1/backup-targets/{id}/disable' => ['post'],
    '/api/v1/policies' => ['get', 'post'],
    '/api/v1/policies/{id}' => ['put'],
    '/api/v1/policies/{id}/enable' => ['post'],
    '/api/v1/policies/{id}/disable' => ['post'],
    '/api/v1/policies/{id}/selection' => ['get', 'put'],
    '/api/v1/policies/{id}/selection/disable' => ['post'],
    '/api/v1/policies/{id}/guest-overrides' => ['put'],
    '/api/v1/policies/{id}/guest-overrides/disable' => ['post'],
    '/api/v1/admin/users' => ['get', 'post'],
    '/api/v1/admin/users/{id}' => ['put'],
    '/api/v1/admin/users/{id}/disable' => ['post'],
    '/api/v1/admin/users/{id}/roles' => ['put'],
    '/api/v1/connections' => ['get'],
    '/api/v1/connections/{id}' => ['get', 'put'],
    '/api/v1/connections/{id}/disable' => ['post'],
    '/api/v1/connections/{id}/endpoints/{endpointId}/disable' => ['post'],
    '/api/v1/connections/onboarding/guidance/{product}' => ['get'],
    '/api/v1/connections/onboarding/activate' => ['post'],
    '/api/v1/connections/{id}/onboarding/rotate' => ['post'],
    '/api/v1/connections/{id}/onboarding/endpoints' => ['post'],
    '/api/v1/connections/{id}/onboarding/endpoints/{endpointId}' => ['put'],
    '/api/v1/operations/requests' => ['post'],
    '/api/v1/operations/requests/{id}/cancel' => ['post'],
];
$operationIds = [];
foreach ($contract['paths'] as $route => $pathItem) {
    if (is_string($route) && isset($authMethods[$route]) && is_array($pathItem)) {
        $method = $authMethods[$route];
        if ([$method] !== array_keys($pathItem) || !is_array($pathItem[$method])) {
            failContract('OpenAPI authentication paths drifted from their exact HTTP methods.');
        }
        $operationId = $pathItem[$method]['operationId'] ?? null;
        if (!is_string($operationId) || '' === $operationId || isset($operationIds[$operationId])) {
            failContract('OpenAPI operation IDs must be non-empty and unique.');
        }
        $operationIds[$operationId] = true;
        continue;
    }
    if (is_string($route) && isset($commandMethods[$route]) && is_array($pathItem)) {
        if ($commandMethods[$route] !== array_keys($pathItem)) {
            failContract('OpenAPI configuration command paths drifted from their exact HTTP methods.');
        }
        foreach ($pathItem as $operation) {
            $operationId = is_array($operation) ? ($operation['operationId'] ?? null) : null;
            if (!is_string($operationId) || '' === $operationId || isset($operationIds[$operationId])) {
                failContract('OpenAPI operation IDs must be non-empty and unique.');
            }
            $operationIds[$operationId] = true;
        }
        continue;
    }
    if (!is_string($route) || !isset($expectedResponses[$route]) || !is_array($pathItem)
        || ['get'] !== array_keys($pathItem) || !is_array($pathItem['get'])) {
        failContract('OpenAPI paths must exactly match the versioned router surface.');
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
    if (!in_array($route, ['/api/v1/inventory/overview','/api/v1/collector/status','/api/v1/operations/dashboard','/api/v1/operations/notifications/health','/api/v1/admin/health'], true)
        && '#/components/responses/InvalidQuery' !== responseReference($operation, '400')) {
        failContract('OpenAPI query operation is missing its stable 400 response.');
    }
    if (in_array($route, [
        '/api/v1/backup-target-candidates',
        '/api/v1/executor-permission-evidence',
        '/api/v1/backup-targets',
        '/api/v1/policies',
        '/api/v1/policies/{id}/selection',
        '/api/v1/shadow/evaluations',
        '/api/v1/shadow/decisions',
        '/api/v1/shadow/decisions/{id}',
        '/api/v1/operations/dashboard',
        '/api/v1/operations/queue',
        '/api/v1/operations/queue/history',
        '/api/v1/operations/pbs-tasks',
        '/api/v1/operations/pbs-tasks/{id}',
        '/api/v1/operations/requests/{id}/events',
        '/api/v1/operations/runs',
        '/api/v1/operations/runs/{id}',
        '/api/v1/operations/runs/{id}/events',
        '/api/v1/operations/runs/{id}/logs',
        '/api/v1/operations/notifications',
        '/api/v1/operations/notifications/health',
    ], true)
        && '#/components/responses/ReadModelUnavailable' !== responseReference($operation, '503')) {
        failContract('OpenAPI backup-target operation is missing its safe 503 response.');
    }
}
$publishedPaths = array_keys($contract['paths']);
$expectedPaths = array_values(array_unique([...array_keys($expectedResponses), ...array_keys($authMethods), ...array_keys($commandMethods)]));
sort($publishedPaths);
sort($expectedPaths);
if ($publishedPaths !== $expectedPaths) {
    failContract('OpenAPI path compatibility baseline changed.');
}
$targetParameters = nestedValue(
    $contract,
    ['paths', '/api/v1/backup-target-candidates', 'get', 'parameters'],
);
$publishedTargetParameters = [];
if (is_array($targetParameters)) {
    foreach ($targetParameters as $parameter) {
        if (!is_array($parameter) || !is_string($parameter['$ref'] ?? null)) {
            failContract('OpenAPI target-candidate query parameters are not exact references.');
        }
        $publishedTargetParameters[] = $parameter['$ref'];
    }
}
if ([
    '#/components/parameters/Limit',
    '#/components/parameters/Cursor',
    '#/components/parameters/ConnectionId',
    '#/components/parameters/ClusterId',
] !== $publishedTargetParameters) {
    failContract('OpenAPI target-candidate query surface drifted.');
}
$executorEvidenceParameters = nestedValue(
    $contract,
    ['paths', '/api/v1/executor-permission-evidence', 'get', 'parameters'],
);
$publishedExecutorEvidenceParameters = [];
if (is_array($executorEvidenceParameters)) {
    foreach ($executorEvidenceParameters as $parameter) {
        if (!is_array($parameter) || !is_string($parameter['$ref'] ?? null)) {
            failContract('OpenAPI executor-evidence query parameters are not exact references.');
        }
        $publishedExecutorEvidenceParameters[] = $parameter['$ref'];
    }
}
if ([
    '#/components/parameters/Limit',
    '#/components/parameters/Cursor',
    '#/components/parameters/ConnectionId',
    '#/components/parameters/ClusterId',
    '#/components/parameters/TargetId',
    '#/components/parameters/NodeId',
    '#/components/parameters/GuestId',
] !== $publishedExecutorEvidenceParameters) {
    failContract('OpenAPI executor-evidence query surface drifted.');
}
$configuredTargetParameters = nestedValue(
    $contract,
    ['paths', '/api/v1/backup-targets', 'get', 'parameters'],
);
$publishedConfiguredTargetParameters = [];
if (is_array($configuredTargetParameters)) {
    foreach ($configuredTargetParameters as $parameter) {
        if (!is_array($parameter) || !is_string($parameter['$ref'] ?? null)) {
            failContract('OpenAPI configured-target query parameters are not exact references.');
        }
        $publishedConfiguredTargetParameters[] = $parameter['$ref'];
    }
}
if ([
    '#/components/parameters/Limit',
    '#/components/parameters/Cursor',
    '#/components/parameters/Search',
    '#/components/parameters/Enabled',
] !== $publishedConfiguredTargetParameters) {
    failContract('OpenAPI configured-target query surface drifted.');
}
$policyParameters = nestedValue($contract, ['paths', '/api/v1/policies', 'get', 'parameters']);
$publishedPolicyParameters = [];
if (is_array($policyParameters)) {
    foreach ($policyParameters as $parameter) {
        if (!is_array($parameter) || !is_string($parameter['$ref'] ?? null)) {
            failContract('OpenAPI policy query parameters are not exact references.');
        }
        $publishedPolicyParameters[] = $parameter['$ref'];
    }
}
if ([
    '#/components/parameters/Limit',
    '#/components/parameters/Cursor',
    '#/components/parameters/Search',
    '#/components/parameters/PolicyStatus',
] !== $publishedPolicyParameters) {
    failContract('OpenAPI policy query surface drifted.');
}
$selectionParameters = nestedValue(
    $contract,
    ['paths', '/api/v1/policies/{id}/selection', 'get', 'parameters'],
);
$publishedSelectionParameters = [];
if (is_array($selectionParameters)) {
    foreach ($selectionParameters as $parameter) {
        if (!is_array($parameter) || !is_string($parameter['$ref'] ?? null)) {
            failContract('OpenAPI policy-selection parameters are not exact references.');
        }
        $publishedSelectionParameters[] = $parameter['$ref'];
    }
}
if ([
    '#/components/parameters/PolicyId',
    '#/components/parameters/Limit',
    '#/components/parameters/Cursor',
] !== $publishedSelectionParameters) {
    failContract('OpenAPI policy-selection query surface drifted.');
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
    $runtimeRoutes[$runtimePath] = array_values(array_unique([
        ...($runtimeRoutes[$runtimePath] ?? []),
        ...$methods,
    ]));
    sort($runtimeRoutes[$runtimePath]);
}
$kernel->shutdown();
ksort($runtimeRoutes);
$stagedReadOnlyRoutes = [];
foreach ($stagedReadOnlyRoutes as $path => $methods) {
    if (($runtimeRoutes[$path] ?? null) !== $methods) {
        failContract('A staged unpublished shadow route is missing or is not GET-only.');
    }
    unset($runtimeRoutes[$path]);
}
$expectedRuntimeRoutes = array_fill_keys(array_keys($expectedResponses), ['GET']);
$expectedRuntimeRoutes['/api/v1/auth/login'] = ['POST'];
$expectedRuntimeRoutes['/api/v1/auth/session'] = ['GET'];
$expectedRuntimeRoutes['/api/v1/auth/logout'] = ['POST'];
foreach ($commandMethods as $path => $methods) {
    $expectedRuntimeRoutes[$path] = array_map('strtoupper', $methods);
    sort($expectedRuntimeRoutes[$path]);
}
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
    'BackupTargetNodeEvidence' => [
        'nodeId', 'nodeName', 'configuredForStorage', 'enabled', 'active', 'capacityStatus',
        'totalBytes', 'usedBytes', 'availableBytes', 'observedAt', 'blockers',
    ],
    'BackupTargetExecutorEvidence' => [
        'status', 'targetCount', 'expectedNodeCount', 'observedNodeCount',
        'vmBackupAuthorized', 'datastoreAllocateAuthorized', 'authorized', 'freshness',
        'observedAt', 'blockers',
    ],
    'PbsBackupTargetEvidence' => [
        'server', 'port', 'datastore', 'namespace', 'mappingObservedAt', 'endpointMatch',
        'pbsConnectionId', 'pbsServerId', 'pbsDatastoreId', 'pbsNamespaceId', 'capacitySemantics',
        'totalBytes', 'usedBytes', 'availableBytes', 'capacityObservedAt', 'blockers',
    ],
    'BackupTargetCandidate' => [
        'id', 'connectionId', 'connectionName', 'clusterId', 'clusterName', 'storageName',
        'storageType', 'shared', 'inventoryState', 'observedAt', 'canEnable', 'nodes', 'executor', 'pbs', 'blockers',
    ],
    'BackupTargetCandidatePage' => ['items', 'page'],
    'ExecutorPermissionEvidence' => [
        'id', 'connectionId', 'clusterId', 'targetId', 'nodeId', 'storageId', 'guestId',
        'evidenceSetRevision', 'endpointId', 'connectionRevision', 'backupCredentialRevision',
        'scanCredentialRevision', 'observedAt', 'freshness', 'vmBackupAuthorized',
        'datastoreAllocateAuthorized', 'authorized', 'missingPermissions',
    ],
    'ExecutorPermissionEvidencePage' => ['items', 'page'],
    'OperationsWorkerHealth' => [
        'status', 'heartbeatAt', 'expiresAt', 'fresh', 'nextActionAt', 'buildVersion', 'currentActivity',
    ],
    'OperationsWorkers' => ['collector', 'backup'],
    'OperationsCollectorSchedule' => [
        'nextScanAt', 'lastAttemptStartedAt', 'lastAttemptFinishedAt', 'lastSuccessfulAppliedAt',
    ],
    'OperationsResourceCounts' => ['systems', 'nodes', 'guests', 'targets', 'policies'],
    'BackupRequestReasonCounts' => ['manual', 'never_backed_up', 'max_age', 'bytes_written'],
    'NotificationStateCounts' => ['pending', 'claimed', 'sent'],
    'BackupNotificationHealth' => ['byState', 'oldestUnsentAt', 'lastErrorCode', 'nextDeliveryAttemptAt'],
    'OperationsAuditEvent' => ['id', 'occurredAt', 'eventType', 'outcome', 'subjectType', 'reasonCode'],
    'OperationsLastSuccessfulRun' => ['runId', 'guestName', 'vmid', 'nodeName', 'targetName', 'finishedAt'],
    'BackupRequest' => [
        'id', 'rootRequestId', 'runId', 'state', 'origin', 'reason', 'priority', 'attempt', 'revision',
        'guestId', 'policyId', 'targetId', 'guestName', 'guestType', 'vmid', 'nodeName', 'policyName', 'targetName',
        'scheduledAt', 'availableAt', 'createdAt', 'updatedAt', 'cancelRequestedAt', 'terminalCode',
    ],
    'BackupNotification' => [
        'id', 'kind', 'state', 'attempt', 'checkNumber', 'deliveryAttempts', 'guestName', 'guestType', 'vmid',
        'node', 'targetLabel', 'problemCode', 'detailCode', 'occurredAt', 'nextRetryAt',
        'consecutiveFailures', 'lastErrorCode', 'createdAt', 'sentAt',
    ],
    'OperationsDashboard' => [
        'workers', 'collectorSchedule', 'resources', 'requestsByState', 'requestsByReason', 'runsByState',
        'oldestPendingAt', 'lastSuccessfulRun', 'staleEvidence', 'shadowBlockers', 'openProblems', 'notifications',
        'recentAuditEvents', 'auditVisible',
    ],
    'AdministrationHealthReport' => ['status', 'checkedAt', 'checks'],
    'ConfiguredBackupTargetAllowedNode' => ['id', 'name'],
    'ConfiguredBackupTarget' => [
        'id', 'revision', 'enabled', 'displayName', 'connectionId', 'connectionName', 'clusterId',
        'clusterName', 'storageId', 'storageName', 'storageType', 'minimumFreeBytes',
        'fixedParallelLimit', 'pbsConnectionId', 'pbsDatastoreId', 'pbsNamespaceId',
        'disabledAt', 'allowedNodes', 'canEnable', 'blockers',
    ],
    'ConfiguredBackupTargetPage' => ['items', 'page'],
    'PolicyRetention' => [
        'legacyMaxFiles', 'keepAll', 'keepLast', 'keepHourly', 'keepDaily', 'keepWeekly',
        'keepMonthly', 'keepYearly',
    ],
    'ConfiguredPolicy' => [
        'id', 'revision', 'status', 'displayName', 'connectionId', 'connectionName', 'clusterId',
        'clusterName', 'targetId', 'targetName', 'priority', 'mode', 'compression',
        'maximumAgeSeconds', 'bytesWrittenThreshold', 'cooldownSeconds', 'schedule',
        'desiredRetention', 'retentionExecutionEnabled', 'failureNotificationRecipients', 'disabledAt', 'canEnable', 'blockers',
    ],
    'PolicyPage' => ['items', 'page'],
    'PolicySelectionEntry' => [
        'id', 'revision', 'status', 'kind', 'scope', 'connectionId', 'clusterId', 'nodeId',
        'guestId', 'subjectName', 'selectionValue', 'mode', 'compression', 'desiredRetention',
        'disabledAt',
    ],
    'PolicySelectionPage' => ['items', 'page'],
    'CollectorRunPage' => ['items', 'page'],
    'CollectorScopePage' => ['items', 'page'],
    'ApiError' => ['error'],
    'ReadModelUnavailableError' => ['error'],
    'OnboardingEndpointInput' => ['host', 'port', 'tlsMode', 'customCaPem', 'sha256Fingerprint'],
    'OnboardingTokenInput' => ['tokenId', 'tokenSecret'],
    'OnboardingPveCredentialsInput' => ['scan', 'backup'],
    'OnboardingPbsCredentialsInput' => ['scan'],
    'OnboardingPveActivationInput' => ['expectedRevision', 'product', 'displayName', 'endpoint', 'credentials'],
    'OnboardingPbsActivationInput' => ['expectedRevision', 'product', 'displayName', 'endpoint', 'credentials'],
    'OnboardingPveEndpointMutationInput' => ['expectedRevision', 'product', 'displayName', 'endpoint', 'credentials'],
    'OnboardingPbsEndpointMutationInput' => ['expectedRevision', 'product', 'displayName', 'endpoint', 'credentials'],
    'OnboardingPveRotationInput' => ['expectedRevision', 'endpointId', 'product', 'displayName', 'endpoint', 'credentials'],
    'OnboardingPbsRotationInput' => ['expectedRevision', 'endpointId', 'product', 'displayName', 'endpoint', 'credentials'],
    'OnboardingGuidanceCommand' => ['id', 'command', 'purpose', 'mutatesRemote', 'containsSecret'],
    'OnboardingGuidance' => ['product', 'commands', 'warnings'],
    'OnboardingIssue' => ['code', 'severity', 'credential', 'path', 'privilege'],
    'OnboardingVerification' => [
        'tls', 'product', 'scanPermissions', 'backupPermissions', 'activation', 'inventory',
        'detectedProduct', 'detectedVersion', 'warnings',
    ],
    'OnboardingMutationResult' => ['status', 'connectionId', 'revision', 'onboardingStatus', 'verification'],
    'OnboardingVerificationError' => ['error'],
    'OnboardingUnavailableError' => ['error'],
    'ConnectionOnboardingState' => ['status', 'verifiedAt', 'inventoryStatusChangedAt', 'lastInventoryRunId'],
];
foreach ($requiredObjects as $name => $required) {
    $schema = $schemas[$name] ?? null;
    if (!is_array($schema)) {
        failContract('OpenAPI is missing exact schema '.$name.'.');
    }
    $optional = match ($name) {
        'ConfiguredBackupTarget' => ['defaultBackupMode', 'defaultCompression', 'defaultLegacyMaxfiles', 'defaultKeepAll', 'defaultKeepLast', 'defaultKeepHourly', 'defaultKeepDaily', 'defaultKeepWeekly', 'defaultKeepMonthly', 'defaultKeepYearly'],
        'ConfiguredPolicy' => ['effectiveMode', 'effectiveCompression', 'effectiveRetention'],
        default => [],
    };
    assertClosedObject($schema, $required, $name, $optional);
}
$backupRun = $schemas['BackupRun'] ?? null;
$backupRunProperties = is_array($backupRun) ? ($backupRun['properties'] ?? null) : null;
$expectedBackupRunProperties = [
    'id', 'requestId', 'rootRequestId', 'guestId', 'state', 'attempt', 'revision',
    'submissionProvenance', 'upid', 'guestName', 'guestType', 'vmid', 'nodeName', 'policyName',
    'targetName', 'startedAt', 'finishedAt', 'requestRevision', 'requestCancelRequestedAt',
    'reason', 'priority', 'exitStatus', 'statusFailureCode', 'stopAttemptClaimedAt',
    'stopAttemptStatus', 'stopAttemptResolvedAt', 'stopFailureCode', 'recoveryOutcome', 'nextLogOffset',
];
if (!is_array($backupRun) || false !== ($backupRun['additionalProperties'] ?? null)
    || !is_array($backupRunProperties) || $expectedBackupRunProperties !== array_keys($backupRunProperties)) {
    failContract('OpenAPI BackupRun has an open or incomplete property surface.');
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

$expectedTargetEnums = [
    'BackupTargetBlockerCode' => array_column(BackupTargetBlockerCode::cases(), 'value'),
    'BackupTargetCapacityStatus' => array_column(BackupTargetCapacityStatus::cases(), 'value'),
    'PbsEndpointMatchStatus' => array_column(PbsEndpointMatchStatus::cases(), 'value'),
    'BackupTargetExecutorStatus' => array_column(BackupTargetExecutorStatus::cases(), 'value'),
];
foreach ($expectedTargetEnums as $name => $expected) {
    if ($expected !== ($schemas[$name]['enum'] ?? null)) {
        failContract('OpenAPI target-candidate enum '.$name.' drifted from its application enum.');
    }
}
if (array_column(TargetActivationBlocker::cases(), 'value')
    !== ($schemas['ConfiguredBackupTargetBlockerCode']['enum'] ?? null)) {
    failContract('OpenAPI configured-target blocker enum drifted from its closed Phase 4.4a contract.');
}
if (array_column(OnboardingIssueCode::cases(), 'value') !== ($schemas['OnboardingIssueCode']['enum'] ?? null)) {
    failContract('OpenAPI onboarding issue codes drifted from the fail-closed application contract.');
}
$onboardingUnions = [
    'OnboardingActivationRequest' => ['OnboardingPveActivationInput', 'OnboardingPbsActivationInput'],
    'OnboardingEndpointMutationRequest' => ['OnboardingPveEndpointMutationInput', 'OnboardingPbsEndpointMutationInput'],
    'OnboardingRotationRequest' => ['OnboardingPveRotationInput', 'OnboardingPbsRotationInput'],
];
foreach ($onboardingUnions as $name => $variants) {
    $schema = $schemas[$name] ?? null;
    $expectedReferences = array_map(
        static fn (string $variant): array => ['$ref' => '#/components/schemas/'.$variant],
        $variants,
    );
    $expectedMapping = [
        'pve' => '#/components/schemas/'.$variants[0],
        'pbs' => '#/components/schemas/'.$variants[1],
    ];
    if (!is_array($schema)
        || $expectedReferences !== ($schema['oneOf'] ?? null)
        || 'product' !== nestedValue($schema, ['discriminator', 'propertyName'])
        || $expectedMapping !== nestedValue($schema, ['discriminator', 'mapping'])) {
        failContract('OpenAPI onboarding union '.$name.' drifted from its product discriminator.');
    }
}
$legacyOnboardingSchemas = [
    'OnboardingCredentialsInput', 'EndpointCommandRequest', 'ConnectionCredentialBootstrap',
    'ConnectionCreateRequest', 'EndpointBootstrap', 'CredentialRotateRequest',
];
foreach ($legacyOnboardingSchemas as $name) {
    if (array_key_exists($name, $schemas)) {
        failContract('OpenAPI still publishes legacy connection schema '.$name.'.');
    }
}
if ('pve' !== nestedValue($schemas, ['OnboardingPveActivationInput', 'properties', 'product', 'const'])
    || 'pbs' !== nestedValue($schemas, ['OnboardingPbsActivationInput', 'properties', 'product', 'const'])
    || 'pve' !== nestedValue($schemas, ['OnboardingPveEndpointMutationInput', 'properties', 'product', 'const'])
    || 'pbs' !== nestedValue($schemas, ['OnboardingPbsEndpointMutationInput', 'properties', 'product', 'const'])
    || 'pve' !== nestedValue($schemas, ['OnboardingPveRotationInput', 'properties', 'product', 'const'])
    || 'pbs' !== nestedValue($schemas, ['OnboardingPbsRotationInput', 'properties', 'product', 'const'])
    || 0 !== nestedValue($schemas, ['OnboardingPveActivationInput', 'properties', 'expectedRevision', 'const'])
    || 0 !== nestedValue($schemas, ['OnboardingPbsActivationInput', 'properties', 'expectedRevision', 'const'])
    || false !== nestedValue($schemas, ['OnboardingGuidanceCommand', 'properties', 'containsSecret', 'const'])
    || true !== nestedValue($schemas, ['OnboardingTokenInput', 'properties', 'tokenSecret', 'writeOnly'])
    || true !== nestedValue($schemas, ['OnboardingEndpointInput', 'properties', 'customCaPem', 'oneOf', '0', 'writeOnly'])
    || 'x509-ca-bundle' !== nestedValue($schemas, ['OnboardingEndpointInput', 'properties', 'customCaPem', 'oneOf', '0', 'format'])
    || 'hostname-or-ip-address' !== nestedValue($schemas, ['OnboardingEndpointInput', 'properties', 'host', 'format'])
    || ['first_automatic_scan_pending', 'not_started']
        !== nestedValue($schemas, ['OnboardingVerification', 'properties', 'inventory', 'enum'])
    || 'first_automatic_scan_pending'
        !== nestedValue($schemas, ['OnboardingMutationResult', 'properties', 'onboardingStatus', 'const'])) {
    failContract('OpenAPI onboarding DTO, secret, or guidance contract drifted.');
}
$connectionDetail = $schemas['ConnectionDetail'] ?? null;
$connectionDetailExtension = is_array($connectionDetail) ? nestedValue($connectionDetail, ['allOf', '1']) : null;
if (!is_array($connectionDetail)
    || '#/components/schemas/ConnectionSummary' !== nestedValue($connectionDetail, ['allOf', '0', '$ref'])
    || !is_array($connectionDetailExtension)
    || ['endpoints', 'credentials', 'onboardingState'] !== ($connectionDetailExtension['required'] ?? null)
    || '#/components/schemas/ConnectionOnboardingState'
        !== nestedValue($connectionDetailExtension, ['properties', 'onboardingState', 'oneOf', '0', '$ref'])
    || 'null' !== nestedValue($connectionDetailExtension, ['properties', 'onboardingState', 'oneOf', '1', 'type'])
    || ['first_automatic_scan_pending', 'inventory_verified', 'inventory_partial', 'inventory_failed']
        !== nestedValue($schemas, ['ConnectionOnboardingStatus', 'enum'])) {
    failContract('OpenAPI connection onboarding-state projection drifted.');
}
$onboardingOperations = [
    '/api/v1/connections/onboarding/activate' => '#/components/schemas/OnboardingActivationRequest',
    '/api/v1/connections/{id}/onboarding/rotate' => '#/components/schemas/OnboardingRotationRequest',
    '/api/v1/connections/{id}/onboarding/endpoints' => '#/components/schemas/OnboardingEndpointMutationRequest',
    '/api/v1/connections/{id}/onboarding/endpoints/{endpointId}' => '#/components/schemas/OnboardingEndpointMutationRequest',
];
foreach ($onboardingOperations as $route => $requestSchema) {
    $method = '/api/v1/connections/{id}/onboarding/endpoints/{endpointId}' === $route ? 'put' : 'post';
    $operation = nestedValue($contract, ['paths', $route, $method]);
    if (!is_array($operation)
        || $requestSchema !== nestedValue($operation, ['requestBody', 'content', 'application/json', 'schema', '$ref'])
        || '#/components/responses/OnboardingApplied' !== responseReference($operation, '200')
        || '#/components/responses/OnboardingRejected' !== responseReference($operation, '422')
        || '#/components/responses/OnboardingUnavailable' !== responseReference($operation, '503')) {
        failContract('OpenAPI onboarding write operation drifted from its verified activation contract.');
    }
}
if (['draft', 'enabled', 'disabled'] !== ($schemas['PolicyStatus']['enum'] ?? null)
    || array_column(PolicyActivationBlockerCode::cases(), 'value') !== ($schemas['PolicyBlockerCode']['enum'] ?? null)) {
    failContract('OpenAPI policy status or blocker enum drifted from its closed read-model contract.');
}
$decimalBytes = $schemas['DecimalBytes'] ?? null;
$uint64Pattern = '^(?:0|[1-9][0-9]{0,18}|1[0-7][0-9]{18}|18[0-3][0-9]{17}|184[0-3][0-9]{16}|1844[0-5][0-9]{15}|18446[0-6][0-9]{14}|184467[0-3][0-9]{13}|1844674[0-3][0-9]{12}|184467440[0-6][0-9]{10}|1844674407[0-2][0-9]{9}|18446744073[0-6][0-9]{8}|1844674407370[0-8][0-9]{6}|18446744073709[0-4][0-9]{5}|184467440737095[0-4][0-9]{4}|18446744073709550[0-9]{3}|18446744073709551[0-5][0-9]{2}|1844674407370955160[0-9]|1844674407370955161[0-5])$';
if (!is_array($decimalBytes)
    || 'string' !== ($decimalBytes['type'] ?? null)
    || 1 !== ($decimalBytes['minLength'] ?? null)
    || 20 !== ($decimalBytes['maxLength'] ?? null)
    || $uint64Pattern !== ($decimalBytes['pattern'] ?? null)
    || UInt64Decimal::MAXIMUM !== ($decimalBytes['x-maximum'] ?? null)) {
    failContract('OpenAPI decimal byte values are not lossless unsigned strings.');
}
$targetPageItems = nestedValue(
    $schemas,
    ['BackupTargetCandidatePage', 'properties', 'items', 'items', '$ref'],
);
$targetNodeItems = nestedValue(
    $schemas,
    ['BackupTargetCandidate', 'properties', 'nodes', 'items', '$ref'],
);
if ('#/components/schemas/BackupTargetCandidate' !== $targetPageItems
    || '#/components/schemas/BackupTargetNodeEvidence' !== $targetNodeItems) {
    failContract('OpenAPI target-candidate page or node evidence reference drifted.');
}
$executorEvidencePageItems = nestedValue(
    $schemas,
    ['ExecutorPermissionEvidencePage', 'properties', 'items', 'items', '$ref'],
);
$executorEvidenceSetRevision = nestedValue(
    $schemas,
    ['ExecutorPermissionEvidence', 'properties', 'evidenceSetRevision'],
);
if ('#/components/schemas/ExecutorPermissionEvidence' !== $executorEvidencePageItems
    || ['VM.Backup', 'Datastore.AllocateSpace'] !== ($schemas['ExecutorPermissionName']['enum'] ?? null)
    || ['fresh', 'stale', 'future'] !== nestedValue(
        $schemas,
        ['ExecutorPermissionEvidence', 'properties', 'freshness', 'enum'],
    )
    || !is_array($executorEvidenceSetRevision)
    || 'string' !== ($executorEvidenceSetRevision['type'] ?? null)
    || '^(?:0|[1-9][0-9]{0,19})$' !== ($executorEvidenceSetRevision['pattern'] ?? null)
    || UInt64Decimal::MAXIMUM !== ($executorEvidenceSetRevision['x-maximum'] ?? null)) {
    failContract('OpenAPI executor-evidence page, permission, or freshness contract drifted.');
}
$configuredTargetPageItems = nestedValue(
    $schemas,
    ['ConfiguredBackupTargetPage', 'properties', 'items', 'items', '$ref'],
);
$configuredAllowedNodeItems = nestedValue(
    $schemas,
    ['ConfiguredBackupTarget', 'properties', 'allowedNodes', 'items', '$ref'],
);
$configuredCanEnable = nestedValue(
    $schemas,
    ['ConfiguredBackupTarget', 'properties', 'canEnable', 'type'],
);
if ('#/components/schemas/ConfiguredBackupTarget' !== $configuredTargetPageItems
    || '#/components/schemas/ConfiguredBackupTargetAllowedNode' !== $configuredAllowedNodeItems
    || 'boolean' !== $configuredCanEnable) {
    failContract('OpenAPI configured-target page, allowed-node, or activation reference drifted.');
}
$policyPageItems = nestedValue($schemas, ['PolicyPage', 'properties', 'items', 'items', '$ref']);
$policySelectionItems = nestedValue(
    $schemas,
    ['PolicySelectionPage', 'properties', 'items', 'items', '$ref'],
);
$policyCanEnable = nestedValue($schemas, ['ConfiguredPolicy', 'properties', 'canEnable', 'type']);
if ('#/components/schemas/ConfiguredPolicy' !== $policyPageItems
    || '#/components/schemas/PolicySelectionEntry' !== $policySelectionItems
    || 'boolean' !== $policyCanEnable) {
    failContract('OpenAPI policy pages or activation reference drifted.');
}
$unavailableCode = nestedValue(
    $schemas,
    ['ReadModelUnavailableError', 'properties', 'error', 'properties', 'code', 'const'],
);
$unavailableMessage = nestedValue(
    $schemas,
    ['ReadModelUnavailableError', 'properties', 'error', 'properties', 'message', 'const'],
);
if ('read_model_unavailable' !== $unavailableCode
    || 'The read model is temporarily unavailable.' !== $unavailableMessage) {
    failContract('OpenAPI target-candidate safe error payload drifted.');
}

foreach ([
    'owner_auth_id', 'encryption_fingerprint', 'verification_upid', 'files_json', 'comment',
    '"endpointHost"', '"certificateFingerprint"',
    '"syncRunId"', '"secret"', '"credentialId"', '"credentialIdentity"', '"secretEnvelope"', '"keyId"',
] as $forbidden) {
    if (str_contains($contents, $forbidden)) {
        failContract('OpenAPI exposes a forbidden internal, endpoint, TLS, or secret field.');
    }
}

fwrite(STDOUT, "OpenAPI v1 router and exact schema contract passed.\n");
