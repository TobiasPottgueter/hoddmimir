<?php

declare(strict_types=1);

namespace App\Tests\Fakes;

use App\Application\Worker\Sleeper;
use RuntimeException;

final class RecordingSleeper implements Sleeper
{
    /** @var list<int> */
    public array $durations = [];

    public function __construct(private readonly ?int $interruptAfterCalls = null)
    {
    }

    public function sleep(int $seconds): void
    {
        $this->durations[] = $seconds;

        if (count($this->durations) === $this->interruptAfterCalls) {
            throw new RuntimeException('Test loop interrupted.');
        }
    }
}
