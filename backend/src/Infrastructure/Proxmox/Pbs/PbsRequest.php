<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsDatastoreId;
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

    public static function permission(string $path): self
    {
        if ('/system/status' !== $path && '/datastore' !== $path
            && !AsciiPatternValidator::matches('/\A\/datastore\/[A-Za-z0-9_][A-Za-z0-9._-]{2,31}\z/D', $path)) {
            throw new InvalidArgumentException('The PBS permission probe path is not allowed.');
        }
        return new self(['access', 'permissions'], ['path' => $path], 262_144);
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

    private static function requireNode(string $node): void
    {
        if (!AsciiPatternValidator::matches('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\z/D', $node)) {
            throw new InvalidArgumentException('The PBS node name is invalid.');
        }
    }
}
