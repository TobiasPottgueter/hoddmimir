<?php

declare(strict_types=1);

namespace App\Application\Collector;

final readonly class RunCollectorWorker implements CollectorWorkerRunner
{
    public function __construct(
        private CollectorWorkerIdentity $identity,
        private CollectorRuntimeLoop $runtimeLoop,
    ) {
    }

    public function run(bool $once): CollectorWorkerRunResult
    {
        try {
            return $this->runtimeLoop->run($this->identity->workerId(), $once);
        } catch (\Throwable) {
            return new CollectorWorkerRunResult(CollectorWorkerRunCode::RuntimeFailed);
        }
    }
}
