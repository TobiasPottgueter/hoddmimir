<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Infrastructure\Filesystem\AtomicFileMaterializer;
use App\Infrastructure\Validation\MaterializationDirectoryValidator;
use InvalidArgumentException;

final readonly class PveCustomCaMaterializer
{
    private const DIRECTORY_NAME = 'proxmox-ca';

    private string $directory;

    public function __construct(
        private AtomicFileMaterializer $files,
        string $baseDirectory = '/app/var',
    ) {
        $directory = MaterializationDirectoryValidator::dedicatedChildPath(
            $baseDirectory,
            self::DIRECTORY_NAME,
        );
        if (null === $directory) {
            throw new InvalidArgumentException('The custom CA materialization directory is invalid.');
        }

        $this->directory = $directory;
    }

    public function materialize(PveCustomCaCertificate $certificate): string
    {
        return $this->files->materialize(
            $this->directory,
            'ca-'.$certificate->fingerprint().'.pem',
            $certificate->pem,
            0700,
            0600,
        );
    }
}
