<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use App\Domain\Security\GlobalLoginThrottleState;
use App\Domain\Security\LoginThrottleState;
use App\Domain\Security\NormalizedUsername;
use DateTimeImmutable;

interface LoginThrottleStore
{
    public function performMaintenance(DateTimeImmutable $now): void;
    public function lockGlobalThrottle(): GlobalLoginThrottleState;
    public function saveGlobalThrottle(GlobalLoginThrottleState $state): void;
    public function lockIpThrottle(string $packedIp): ?LoginThrottleState;
    public function saveIpThrottle(string $packedIp, LoginThrottleState $state): void;
    public function clearIpThrottle(string $packedIp): void;
    public function lockThrottle(NormalizedUsername $username, string $packedIp): ?LoginThrottleState;
    public function save(NormalizedUsername $username, string $packedIp, LoginThrottleState $state): void;
    public function clear(NormalizedUsername $username, string $packedIp): void;
}
