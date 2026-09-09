<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Infrastructure\Filesystem\AtomicFileMaterializer;
use App\Infrastructure\Validation\MaterializationDirectoryValidator;
use InvalidArgumentException;

final readonly class PbsCustomCaMaterializer
{
    private string $directory;

    public function __construct(private AtomicFileMaterializer $files, string $baseDirectory = '/app/var')
    {
        $directory = MaterializationDirectoryValidator::dedicatedChildPath($baseDirectory, 'pbs-ca');
        if (null === $directory) {
            throw new InvalidArgumentException('The PBS custom CA materialization directory is invalid.');
        }
        $this->directory = $directory;
    }

    public function materialize(PbsCustomCaCertificate $certificate): string
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
