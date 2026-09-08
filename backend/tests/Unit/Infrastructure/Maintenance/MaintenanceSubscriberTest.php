<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Maintenance;

use App\Application\Maintenance\MaintenanceAccess;
use App\Application\Maintenance\MaintenanceActivity;
use App\Application\Maintenance\MaintenancePermit;
use App\Presentation\Http\EventSubscriber\MaintenanceSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class MaintenanceSubscriberTest extends TestCase
{
    public function testMaintenanceRejectsRequestsBeforeSessionAuthenticationOrWrites(): void
    {
        $access = $this->createMock(MaintenanceAccess::class);
        $access->expects(self::once())->method('acquire')->with(MaintenanceActivity::ApplicationWrite)->willReturn(null);
        $subscriber = new MaintenanceSubscriber($access);
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create('/api/v1/connections', 'POST'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->request($event);
        self::assertSame(503, $event->getResponse()?->getStatusCode());
        self::assertSame('30', $event->getResponse()->headers->get('Retry-After'));
    }
    public function testOpenRequestHoldsPermitUntilFinishingIncludingExceptionResponses(): void
    {
        $permit = $this->createMock(MaintenancePermit::class);
        $permit->expects(self::once())->method('release');
        $access = $this->createStub(MaintenanceAccess::class);
        $access->method('acquire')->willReturn($permit);
        $subscriber = new MaintenanceSubscriber($access);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/api/v1/connections');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->request($event);
        self::assertNull($event->getResponse());
        $subscriber->finish(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->finish(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
    }
    public function testReadOnlyHealthAndNestedRequestsDoNotAcquireAnotherPermit(): void
    {
        $access = $this->createMock(MaintenanceAccess::class);
        $access->expects(self::never())->method('acquire');
        $subscriber = new MaintenanceSubscriber($access);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $subscriber->request(new RequestEvent($kernel, Request::create('/api/health'), HttpKernelInterface::MAIN_REQUEST));
        $subscriber->request(new RequestEvent($kernel, Request::create('/api/v1/connections'), HttpKernelInterface::SUB_REQUEST));
        $subscriber->finish(new FinishRequestEvent($kernel, Request::create('/api/v1/connections'), HttpKernelInterface::SUB_REQUEST));
        self::assertNotEmpty(MaintenanceSubscriber::getSubscribedEvents());
    }
}
