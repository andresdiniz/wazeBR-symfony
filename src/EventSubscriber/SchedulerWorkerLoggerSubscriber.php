<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

final class SchedulerWorkerLoggerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $schedulerLogger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => ['onStarted', 100],
            WorkerStoppedEvent::class => ['onStopped', 100],
        ];
    }

    public function onStarted(WorkerStartedEvent $event): void
    {
        $this->schedulerLogger->info('══ Worker iniciado (transport: {t}) ══', [
            't'  => $this->getTransportNames($event),
            'at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ]);
    }

    public function onStopped(WorkerStoppedEvent $event): void
    {
        $this->schedulerLogger->info('══ Worker encerrado (transport: {t}) ══', [
            't'  => $this->getTransportNames($event),
            'at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ]);
    }

    /**
     * Extrai os nomes dos transports do worker do evento.
     *
     * WorkerStartedEvent e WorkerStoppedEvent só expõem getWorker();
     * o nome do receiver está em getWorker()->getMetadata()->getTransportNames().
     */
    private function getTransportNames(object $event): string
    {
        $names = $event->getWorker()->getMetadata()->getTransportNames();

        return $names === [] ? 'unknown' : implode(',', $names);
    }
}
