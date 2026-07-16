<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\ExecutorEvidence;

use SensitiveParameter;

interface PveExecutorEvidenceGetTransport
{
    public function get(PveExecutorEvidenceRequest $request, #[SensitiveParameter] string $authorization): mixed;
}
