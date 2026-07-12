<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsTaskListQuery;
use App\Application\Proxmox\Pbs\PbsTaskPass;
use App\Infrastructure\Validation\AsciiPatternValidator;
use InvalidArgumentException;

final readonly class PbsRequest
{
    /**
     * @param non-empty-list<string>    $pathSegments
     * @param array<string, string|int> $query
     */
    private function __construct(
        public array $pathSegments,
        public array $query,
        public int $maximumBodyBytes,
    ) {
    }

    public static function version(): self { return new self(['version'], [], 65_536); }
    public static function ping(): self { return new self(['ping'], [], 65_536); }
    public static function nodes(): self { return new self(['nodes'], [], 262_144); }
    public static function datastoreConfigurations(): self { return new self(['config', 'datastore'], [], 8_388_608); }
    public static function datastores(): self { return new self(['admin', 'datastore'], [], 8_388_608); }
    public static function pruneJobs(): self { return new self(['admin', 'prune'], [], 4_194_304); }
    public static function syncJobs(): self { return new self(['admin', 'sync'], ['sync-direction' => 'all'], 4_194_304); }
    public static function verifyJobs(): self { return new self(['admin', 'verify'], [], 4_194_304); }

    public static function permission(string $path): self
    {
        $fixedPaths = ['/system/status' => true, '/system/tasks' => true, '/datastore' => true, '/remote' => true];
        if (isset($fixedPaths[$path])) {
            return new self(['access', 'permissions'], ['path' => $path], 262_144);
        }
        if (strlen($path) <= 128 && AsciiPatternValidator::matches(
            '/\A\/datastore\/[A-Za-z0-9_][A-Za-z0-9._-]{2,31}(?:\/[A-Za-z0-9_][A-Za-z0-9._-]*){0,8}\z/D',
            $path,
        )) {
            return new self(['access', 'permissions'], ['path' => $path], 262_144);
        }
        throw new InvalidArgumentException('The PBS permission probe path is not allowed.');
    }

    public static function nodeStatus(string $node): self
    {
        self::requireNode($node);
        return new self(['nodes', $node, 'status'], [], 262_144);
    }

    public static function instanceIdentity(string $node): self
    {
        self::requireNode($node);
        return new self(['nodes', $node, 'identity'], [], 262_144);
    }

    public static function datastoreStatus(PbsDatastoreId $id): self
    {
        return new self(['admin', 'datastore', $id->value, 'status'], ['verbose' => 0], 262_144);
    }

    public static function namespaces(PbsDatastoreId $id, int $maximumBodyBytes): self
    {
        return new self(
            ['admin', 'datastore', $id->value, 'namespace'],
            ['max-depth' => 7],
            self::requireBodyLimit($maximumBodyBytes),
        );
    }

    public static function snapshots(
        PbsDatastoreId $id,
        \App\Application\Proxmox\Pbs\PbsNamespace $namespace,
        int $maximumBodyBytes,
    ): self {
        return new self(
            ['admin', 'datastore', $id->value, 'snapshots'],
            $namespace->isRoot() ? [] : ['ns' => $namespace->value],
            self::requireBodyLimit($maximumBodyBytes),
        );
    }

    public static function tasks(string $node, PbsTaskListQuery $taskQuery): self
    {
        self::requireNode($node);
        $query = [
            'start' => $taskQuery->start,
            'limit' => $taskQuery->limit,
            'typefilter' => $taskQuery->family->value,
        ];
        if (PbsTaskPass::Running === $taskQuery->pass) {
            $query['running'] = 1;
        } else {
            /** @var \App\Application\Proxmox\Pbs\PbsTaskWindow $window Immutable query invariant. */
            $window = $taskQuery->window;
            $query['since'] = $window->since;
            $query['until'] = $window->until;
        }
        return new self(['nodes', $node, 'tasks'], $query, 2_097_152);
    }

    private static function requireNode(string $node): void
    {
        if (!AsciiPatternValidator::matches('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\z/D', $node)) {
            throw new InvalidArgumentException('The PBS node name is invalid.');
        }
    }

    private static function requireBodyLimit(int $maximumBodyBytes): int
    {
        if ($maximumBodyBytes < 65_536 || $maximumBodyBytes > 268_435_456) {
            throw new InvalidArgumentException('The PBS response body limit is invalid.');
        }
        return $maximumBodyBytes;
    }
}
