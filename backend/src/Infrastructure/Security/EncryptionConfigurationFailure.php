<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

enum EncryptionConfigurationFailure: string
{
    case SecretFileUnavailable = 'secret_file_unavailable';
    case InvalidJson = 'invalid_json';
    case InvalidStructure = 'invalid_structure';
    case InvalidRevision = 'invalid_revision';
    case InvalidPrimaryKey = 'invalid_primary_key';
    case InvalidKey = 'invalid_key';
    case DuplicateKey = 'duplicate_key';
}
