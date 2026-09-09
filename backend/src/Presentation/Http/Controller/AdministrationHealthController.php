<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\Health\HealthCheck;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdministrationHealthController
{
    public function __construct(private HealthCheck $healthCheck)
    {
    }

    #[Route('/api/v1/admin/health', name: 'api_v1_admin_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $report = $this->healthCheck->check();

        return new JsonResponse($report->toArray());
    }
}
