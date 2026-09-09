<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

enum SecretCipherFailure: string
{
    case EncryptionFailed = 'encryption_failed';
    case MalformedEnvelope = 'malformed_envelope';
    case UnsupportedVersion = 'unsupported_version';
    case UnknownKey = 'unknown_key';
    case DecryptionFailed = 'decryption_failed';
}
