<?php

declare(strict_types=1);

namespace App\Infrastructure\Process;

use App\Application\Collector\CollectorWorkerId;
use App\Application\Collector\CollectorWorkerIdentity;
use App\Application\Collector\CollectorWorkerIdentityReader;
use App\Infrastructure\Filesystem\AtomicFileMaterializer;
use RuntimeException;
use Throwable;

final class PersistentCollectorWorkerIdentity implements CollectorWorkerIdentity, CollectorWorkerIdentityReader
{
    private const string DIRECTORY = 'collector-runtime';
    private const string FILENAME = 'worker-id';

    private ?CollectorWorkerId $cached = null;

    public function __construct(
        private readonly AtomicFileMaterializer $files,
        private readonly string $baseDirectory = '/app/var',
    ) {
    }

    public function workerId(): CollectorWorkerId
    {
        if (null !== $this->cached) {
            return $this->cached;
        }

        $path = $this->path();
        if (file_exists($path)) {
            $this->assertSecureFile($path);
        }

        try {
            $hex = bin2hex(random_bytes(16));
        } catch (Throwable) {
            throw new RuntimeException('The collector worker identity could not be generated.');
        }

        $temporaryPath = $this->files->materialize(
            $this->directory(),
            '.worker-id-'.$hex,
            $hex."\n",
            0700,
            0600,
        );
        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new RuntimeException('The collector worker identity could not be installed.');
        }
        $this->assertSecureFile($path);

        $bytes = hex2bin($hex);
        if (!is_string($bytes)) {
            throw new RuntimeException('The collector worker identity could not be generated.');
        }

        return $this->cached = new CollectorWorkerId($bytes);
    }

    public function existingWorkerId(): ?CollectorWorkerId
    {
        if (null !== $this->cached) {
            return $this->cached;
        }

        $path = $this->path();
        if (!file_exists($path)) {
            return null;
        }
        $this->assertSecureFile($path);
        $contents = file_get_contents($path);
        if (!is_string($contents) || 1 !== preg_match('/^[0-9a-f]{32}\n$/D', $contents)) {
            throw new RuntimeException('The collector worker identity file is malformed.');
        }
        $bytes = hex2bin(substr($contents, 0, 32));
        if (!is_string($bytes)) {
            throw new RuntimeException('The collector worker identity file is malformed.');
        }

        return $this->cached = new CollectorWorkerId($bytes);
    }

    private function assertSecureFile(string $path): void
    {
        clearstatcache(true, $path);
        $permissions = fileperms($path);
        if (is_link($path) || !is_file($path) || !is_int($permissions) || 0600 !== ($permissions & 0777)) {
            throw new RuntimeException('The collector worker identity file is unsafe.');
        }
    }

    private function directory(): string
    {
        return rtrim($this->baseDirectory, '/').'/'.self::DIRECTORY;
    }

    private function path(): string
    {
        return $this->directory().'/'.self::FILENAME;
    }
}
