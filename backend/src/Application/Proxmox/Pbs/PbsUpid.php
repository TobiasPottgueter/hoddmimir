<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsUpid
{
    public string $pidHex;
    public string $processStartHex;
    public string $taskIdHex;
    public string $startTimeHex;
    public int $pid;
    public int $processStart;
    public int $startTime;
    public string $node;
    public string $workerType;
    public ?string $workerId;
    public string $authId;

    public function __construct(public string $value)
    {
        if (strlen($value) > 2048 || 1 !== preg_match(
            '/\AUPID:(?<node>[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?):(?<pid>[0-9A-Fa-f]{8}):(?<pstart>[0-9A-Fa-f]{8,9}):(?<task>[0-9A-Fa-f]{8,16}):(?<start>[0-9A-Fa-f]{8}):(?<type>[^:\s]+):(?<id>[^:\s]*):(?<auth>[^:\s]+):\z/D',
            $value,
            $match,
        )) {
            throw new InvalidArgumentException('The PBS UPID is invalid.');
        }
        if (strlen($match['type']) > 255 || strlen($match['id']) > 1024 || strlen($match['auth']) > 255) {
            throw new InvalidArgumentException('The PBS UPID fields exceed their safety limits.');
        }
        $this->node = $match['node'];
        $this->pidHex = strtolower($match['pid']);
        $this->processStartHex = strtolower($match['pstart']);
        $this->taskIdHex = strtolower($match['task']);
        $this->startTimeHex = strtolower($match['start']);
        $this->pid = (int) hexdec($match['pid']);
        $this->processStart = (int) hexdec($match['pstart']);
        $this->startTime = (int) hexdec($match['start']);
        $this->workerType = $match['type'];
        $this->workerId = '' === $match['id'] ? null : $match['id'];
        $this->authId = $match['auth'];
    }
}
