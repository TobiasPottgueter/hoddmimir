<?php

declare(strict_types=1);

namespace App\Presentation\Cli;

use App\Application\Maintenance\MaintenanceAccess;
use App\Application\Maintenance\MaintenanceActivity;
use App\Application\Maintenance\MaintenancePermit;
use App\Application\Maintenance\MaintenancePhase;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class MaintenanceConsoleSubscriber implements EventSubscriberInterface
{
    private ?MaintenancePermit $permit = null;

    public function __construct(private readonly MaintenanceAccess $maintenance) {}

    public static function getSubscribedEvents(): array
    {
        return [ConsoleEvents::COMMAND => ['command', 4096], ConsoleEvents::TERMINATE => ['terminate', -4096]];
    }

    public function command(ConsoleCommandEvent $event): void
    {
        $name = $event->getCommand()?->getName() ?? '';
        // Workers hold permits at operation boundaries. Deployment probes are
        // read-only or transactional checks; DDL runs only while writers are stopped.
        if (in_array($name, ['hoddmimir:worker:data', 'hoddmimir:worker:backup', 'hoddmimir:worker:health',
            'hoddmimir:worker:readiness', 'hoddmimir:maintenance:probe', 'hoddmimir:maintenance:validate',
            'doctrine:migrations:up-to-date', 'doctrine:migrations:status'], true)) return;
        if ('doctrine:migrations:migrate' === $name && MaintenancePhase::Frozen === $this->maintenance->phase()) return;
        $this->permit = $this->maintenance->acquire(MaintenanceActivity::ApplicationWrite);
        if (null === $this->permit) {
            $event->getOutput()->writeln('Hoddmímir maintenance blocks this command.');
            $event->disableCommand();
        }
    }

    public function terminate(ConsoleTerminateEvent $event): void
    {
        $this->permit?->release();
        $this->permit = null;
    }
}
