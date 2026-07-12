<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use App\Application\Proxmox\Pve\PvePbsStorageMapping;
use App\Application\Proxmox\Pve\PveStorageConfiguration;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PveStorageObservation
{
    /** @var list<string> */
    public array $content;

    /** @var null|list<string> */
    public ?array $nodeAllowlist;

    public DateTimeImmutable $observedAt;

    /**
     * @param list<string>      $content
     * @param null|list<string> $nodeAllowlist
     */
    public function __construct(
        public string $storageId,
        public string $storageType,
        array $content,
        ?array $nodeAllowlist,
        public bool $disabled,
        public bool $shared,
        public ?PvePbsStorageMapping $pbsMapping,
        DateTimeImmutable $observedAt,
    ) {
        if (!\App\Application\Proxmox\Pve\PveStorageIdValidator::isValid($storageId)
            || !$this->isVisibleAscii($storageType, 64)) {
            throw new InvalidArgumentException('The PVE storage observation identifiers are invalid.');
        }
        if ([] === $content) {
            throw new InvalidArgumentException('The PVE storage observation content must not be empty.');
        }
        $contentSet = [];
        foreach ($content as $token) {
            if (!$this->isVisibleAscii($token, 64)) {
                throw new InvalidArgumentException('The PVE storage content token is invalid.');
            }
            $contentSet[$token] = true;
        }
        ksort($contentSet, SORT_STRING);
        $this->content = array_keys($contentSet);

        if (null !== $nodeAllowlist) {
            if ([] === $nodeAllowlist) {
                throw new InvalidArgumentException('The PVE storage node allowlist must not be empty.');
            }
            $nodeSet = [];
            foreach ($nodeAllowlist as $node) {
                if (!PveCoreTextValidator::isNodeName($node)) {
                    throw new InvalidArgumentException('The PVE storage node allowlist is invalid.');
                }
                $nodeSet[$node] = true;
            }
            ksort($nodeSet, SORT_STRING);
            $nodeAllowlist = array_keys($nodeSet);
        }
        $this->nodeAllowlist = $nodeAllowlist;
        if (('pbs' === $storageType) !== (null !== $pbsMapping)) {
            throw new InvalidArgumentException('The PVE PBS storage type and mapping must be consistent.');
        }
        if (null !== $pbsMapping
            && (!$this->isGraphAscii($pbsMapping->server, 255)
                || !$this->isDatastore($pbsMapping->datastore)
                || (null !== $pbsMapping->namespace && !$this->isGraphAscii($pbsMapping->namespace, 255)))) {
            throw new InvalidArgumentException('The PVE PBS storage mapping contains invalid text.');
        }
        $this->observedAt = $observedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function fromConfiguration(PveStorageConfiguration $configuration, DateTimeImmutable $observedAt): self
    {
        return new self(
            $configuration->storageId,
            $configuration->storageType,
            $configuration->content->tokens,
            $configuration->nodeAllowlist,
            $configuration->disabled,
            $configuration->shared,
            $configuration->pbsMapping,
            $observedAt,
        );
    }

    public function supportsBackup(): bool
    {
        foreach ($this->content as $token) {
            if ('backup' === $token) {
                return true;
            }
        }

        return false;
    }

    private function isVisibleAscii(string $value, int $maximumLength): bool
    {
        $length = strlen($value);

        return $length >= 1 && $length <= $maximumLength && $length === strspn($value, " !\"#$%&'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~");
    }

    private function isGraphAscii(string $value, int $maximumLength): bool
    {
        $length = strlen($value);

        return $length >= 1 && $length <= $maximumLength && $length === strspn($value, "!\"#$%&'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~");
    }

    private function isDatastore(string $value): bool
    {
        $length = strlen($value);
        if ($length < 1 || $length > 190 || !$this->isAsciiAlphaNumeric($value[0])) {
            return false;
        }

        return $length === strspn($value, '-.0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz');
    }

    private function isAsciiAlphaNumeric(string $value): bool
    {
        return ($value >= '0' && $value <= '9')
            || ($value >= 'A' && $value <= 'Z')
            || ($value >= 'a' && $value <= 'z');
    }
}
