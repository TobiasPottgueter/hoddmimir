<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use App\Application\Security\EncryptedSecret;
use App\Application\Security\PlaintextSecret;
use App\Infrastructure\Security\EncryptionKeyRing;
use SensitiveParameterValue;
use Throwable;

final readonly class SensitiveDataRedactor
{
    public const REDACTED = '[REDACTED]';

    private const MAXIMUM_DEPTH = 8;

    /**
     * @var list<string>
     */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'authorization',
        'cookie',
        'csrf',
        'password',
        'passwd',
        'secret',
        'token',
        'ticket',
        'totp',
        'privatekey',
        'apikey',
        'encryptionkey',
        'ciphertext',
        'encryptedsecret',
        'databaseurl',
        'dsn',
    ];

    public function redactMessage(string $message): string
    {
        $patterns = [
            '~\b(?:authorization|proxy-authorization)\s*[:=]\s*[^\r\n]+~i'
                => 'Authorization: '.self::REDACTED,
            '~\b(?:set-cookie|cookie)\s*[:=]\s*[^\r\n]+~i'
                => 'Cookie: '.self::REDACTED,
            '~\b(PVEAPIToken|PBSAPIToken|PVEAuthCookie|CSRFPreventionToken)\s*=\s*[^\s,;]+~i'
                => '$1='.self::REDACTED,
            '~([a-z][a-z0-9+.-]*://[^:/@\s]+:)[^@\s]+(@)~i'
                => '$1'.self::REDACTED.'$2',
            '~([?&][A-Za-z0-9_-]*(?:password|passwd|pwd|secret|token|ticket|totp|otp|api[_-]?key|csrf)[A-Za-z0-9_-]*=)[^&#\s]*~i'
                => '$1'.self::REDACTED,
            '~["\']?\b([A-Za-z0-9_-]*(?:password|passwd|pwd|secret|token|ticket|totp|otp|api[_-]?key|csrf)[A-Za-z0-9_-]*)\b["\']?\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;}&]+)~i'
                => '$1='.self::REDACTED,
        ];

        foreach ($patterns as $pattern => $replacement) {
            $redacted = preg_replace($pattern, $replacement, $message);
            if ($redacted !== null) {
                $message = $redacted;
            }
        }

        return $message;
    }

    public function redact(mixed $value): mixed
    {
        return $this->redactAtDepth($value, 0);
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    public function redactContext(array $context): array
    {
        return $this->redactArray($context, 0);
    }

    private function redactAtDepth(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAXIMUM_DEPTH) {
            return '[MAXIMUM_DEPTH_REACHED]';
        }

        if (
            $value instanceof PlaintextSecret
            || $value instanceof EncryptedSecret
            || $value instanceof EncryptionKeyRing
            || $value instanceof SensitiveParameterValue
        ) {
            return self::REDACTED;
        }

        if ($value instanceof Throwable) {
            return [
                'type' => $value::class,
                'code' => $value->getCode(),
                'message' => $this->redactMessage($value->getMessage()),
            ];
        }

        if (is_array($value)) {
            return $this->redactArray($value, $depth);
        }

        if (is_string($value)) {
            return $this->redactMessage($value);
        }

        if (is_object($value)) {
            return sprintf('[OBJECT %s]', $value::class);
        }

        if (is_resource($value)) {
            return '[RESOURCE]';
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function redactArray(array $value, int $depth): array
    {
        $redacted = [];
        foreach ($value as $key => $item) {
            $redacted[$key] = is_string($key) && $this->isSensitiveKey($key)
                ? self::REDACTED
                : $this->redactAtDepth($item, $depth + 1);
        }

        return $redacted;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = preg_replace('/[^a-z0-9]+/i', '', strtolower($key));
        if ($normalized === null || $normalized === '') {
            return false;
        }

        if ($normalized === 'pwd' || $normalized === 'otp') {
            return true;
        }

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
