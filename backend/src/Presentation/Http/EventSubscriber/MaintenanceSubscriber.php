<?php

declare(strict_types=1);

namespace App\Presentation\Http\EventSubscriber;

use App\Application\Maintenance\MaintenanceAccess;
use App\Application\Maintenance\MaintenanceActivity;
use App\Application\Maintenance\MaintenancePermit;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class MaintenanceSubscriber implements EventSubscriberInterface
{
    private const string PERMIT = '_hoddmimir_maintenance_permit';

    public function __construct(private MaintenanceAccess $maintenance) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['request', 4096], KernelEvents::FINISH_REQUEST => ['finish', -4096]];
    }

    public function request(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $request = $event->getRequest();
        if ('GET' === $request->getMethod() && '/api/health' === $request->getPathInfo()) return;
        $permit = $this->maintenance->acquire(MaintenanceActivity::ApplicationWrite);
        if (null === $permit) {
            $event->setResponse(new JsonResponse(['error' => ['code' => 'maintenance', 'message' => 'Hoddmímir wird gewartet.']], 503, ['Retry-After' => '30', 'Cache-Control' => 'no-store']));
            return;
        }
        $request->attributes->set(self::PERMIT, $permit);
    }

    public function finish(FinishRequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $permit = $event->getRequest()->attributes->get(self::PERMIT);
        if ($permit instanceof MaintenancePermit) $permit->release();
        $event->getRequest()->attributes->remove(self::PERMIT);
    }
}
