<?php

declare(strict_types=1);

namespace App\Application\Inventory\Capability;

use App\Application\Inventory\Connection\ProxmoxProduct;
use InvalidArgumentException;
use JsonException;

final readonly class CapabilityProfile
{
    public const int PROFILE_VERSION = 1;

    /** @var array<string, bool|int|string> */
    public array $capabilities;

    /** @param array<string, bool|int|string> $capabilities */
    public function __construct(
        public ProxmoxProduct $product,
        public int $versionMajor,
        public int $versionMinor,
        public ?int $versionPatch,
        public ?string $releaseName,
        public string $rawVersion,
        array $capabilities,
    ) {
        if ($versionMajor < 1 || $versionMinor < 0 || (null !== $versionPatch && $versionPatch < 0)
            || '' === $rawVersion || strlen($rawVersion) > 255
            || (null !== $releaseName && ('' === $releaseName || strlen($releaseName) > 128))
            || [] === $capabilities) {
            throw new InvalidArgumentException('The capability profile is invalid.');
        }
        ksort($capabilities, SORT_STRING);
        foreach ($capabilities as $name => $value) {
            $nameLength = strlen($name);
            $nameIsValid = $nameLength >= 1
                && $nameLength <= 64
                && 1 === strspn($name, 'abcdefghijklmnopqrstuvwxyz', 0, 1)
                && $nameLength === strspn($name, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
            if (!$nameIsValid
                // @phpstan-ignore function.alreadyNarrowedType, booleanAnd.alwaysFalse (enforce the runtime boundary promised by PHPDoc)
                || (!is_bool($value) && !is_int($value) && !is_string($value))) {
                throw new InvalidArgumentException('The capability profile contains an invalid capability.');
            }
        }
        $this->capabilities = $capabilities;
    }

    /** @throws JsonException */
    public function capabilitiesJson(): string
    {
        return json_encode($this->capabilities, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    public function canonicalDocument(string $endpointHex): array
    {
        return [
            'capabilities' => $this->capabilities,
            'endpointId' => $endpointHex,
            'product' => $this->product->value,
            'profileVersion' => self::PROFILE_VERSION,
            'rawVersion' => $this->rawVersion,
            'releaseName' => $this->releaseName,
            'versionMajor' => $this->versionMajor,
            'versionMinor' => $this->versionMinor,
            'versionPatch' => $this->versionPatch,
        ];
    }
}
