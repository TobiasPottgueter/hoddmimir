<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\Health\HealthCheck;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HealthController
{
    public function __construct(private HealthCheck $healthCheck)
    {
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->healthCheck->check()->toArray());
    }
}
