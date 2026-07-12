<?php

declare(strict_types=1);

namespace App\Application\Inventory\ReadModel;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use ValueError;

final readonly class PageCursor
{
    private const int VERSION = 1;
    private const int MAXIMUM_OPAQUE_LENGTH = 2048;

    private function __construct(
        public PageCursorKind $kind,
        public string $context,
        public string $first,
        public string $second,
    ) {
    }

    public static function decode(string $opaque): self
    {
        if ('' === $opaque) {
            throw self::invalid();
        }
        $opaqueLength = strlen($opaque);
        if ($opaqueLength > self::MAXIMUM_OPAQUE_LENGTH) {
            throw self::invalid();
        }
        if ($opaqueLength !== strspn($opaque, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-')) {
            throw self::invalid();
        }
        $padding = (4 - strlen($opaque) % 4) % 4;
        $base64 = '';
        for ($index = 0; $index < $opaqueLength; ++$index) {
            $base64 .= match ($opaque[$index]) {
                '-' => '+',
                '_' => '/',
                default => $opaque[$index],
            };
        }
        $padded = $base64.str_repeat('=', $padding);
        $json = base64_decode($padded, true);
        if (!is_string($json)) {
            throw self::invalid();
        }
        try {
            $payload = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::invalid();
        }
        if (!is_array($payload)
            || ['v', 'kind', 'context', 'first', 'second'] !== array_keys($payload)
            || self::VERSION !== ($payload['v'] ?? null)
            || !is_string($payload['kind'] ?? null)
            || !is_string($payload['context'] ?? null)
            || !is_string($payload['first'] ?? null)
            || !is_string($payload['second'] ?? null)) {
            throw self::invalid();
        }
        try {
            $kind = PageCursorKind::from($payload['kind']);
        } catch (ValueError) {
            throw self::invalid();
        }
        $cursor = new self($kind, $payload['context'], $payload['first'], $payload['second']);
        $cursor->validate();

        return $cursor;
    }

    public static function resource(string $context, string $displayName, string $id): self
    {
        $cursor = new self(PageCursorKind::Resource, $context, $displayName, $id);
        $cursor->validate();

        return $cursor;
    }

    public static function collectorRun(string $startedAt, string $id): self
    {
        $cursor = new self(PageCursorKind::CollectorRun, self::collectorRunsContext(), $startedAt, $id);
        $cursor->validate();

        return $cursor;
    }

    public static function collectorScope(string $context, string $scopeType, string $scopeKey): self
    {
        $cursor = new self(PageCursorKind::CollectorScope, $context, $scopeType, $scopeKey);
        $cursor->validate();

        return $cursor;
    }

    public static function context(string ...$parts): string
    {
        $material = '';
        foreach ($parts as $index => $part) {
            if ($index > 0) {
                $material .= "\0";
            }
            $material .= $part;
        }
        return hash('sha256', $material);
    }

    public static function collectorRunsContext(): string
    {
        return self::context('collector-runs-v1');
    }

    public function assertContext(PageCursorKind $kind, string $context): void
    {
        if ($this->kind !== $kind || !hash_equals($this->context, $context)) {
            throw self::invalid();
        }
    }

    public function opaque(): string
    {
        try {
            $json = json_encode([
                'v' => self::VERSION,
                'kind' => $this->kind->value,
                'context' => $this->context,
                'first' => $this->first,
                'second' => $this->second,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw self::invalid();
        }

        $base64 = base64_encode($json);
        $urlSafe = '';
        $length = strlen($base64);
        for ($index = 0; $index < $length; ++$index) {
            $urlSafe .= match ($base64[$index]) {
                '+' => '-',
                '/' => '_',
                default => $base64[$index],
            };
        }
        return rtrim($urlSafe, '=');
    }

    private function validate(): void
    {
        if (64 !== strlen($this->context) || 64 !== strspn($this->context, '0123456789abcdef')) {
            throw self::invalid();
        }
        if (PageCursorKind::Resource === $this->kind) {
            $this->validateText($this->first, 255);
            new ReadModelIdentifier($this->second);

            return;
        }
        if (PageCursorKind::CollectorRun === $this->kind) {
            $timestamp = DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s.u\Z',
                $this->first,
                new DateTimeZone('UTC'),
            );
            if (false === $timestamp
                || $timestamp->format('Y-m-d\TH:i:s.u\Z') !== $this->first
                || $timestamp->format('Y') < '1000') {
                throw self::invalid();
            }
            new ReadModelIdentifier($this->second);

            return;
        }
        $this->validateText($this->first, 32);
        $this->validateText($this->second, 512);
    }

    private function validateText(string $value, int $maximumLength): void
    {
        if ('' === $value) {
            throw self::invalid();
        }
        if (strlen($value) > $maximumLength) {
            throw self::invalid();
        }
        $length = strlen($value);
        for ($index = 0; $index < $length; ++$index) {
            if ("\0" === $value[$index]) {
                throw self::invalid();
            }
        }
    }

    private static function invalid(): InvalidArgumentException
    {
        return new InvalidArgumentException('The pagination cursor is invalid.');
    }
}
