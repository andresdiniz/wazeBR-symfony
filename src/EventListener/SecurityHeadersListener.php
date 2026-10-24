<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class SecurityHeadersListener implements EventSubscriberInterface
{
    public function onKernelResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $headers = $response->headers;

        // Remove headers que expõem tecnologia
        $headers->remove('X-Powered-By');
        $headers->remove('X-Debug-Token');
        $headers->remove('X-Debug-Token-Link');
        $headers->remove('Server');
        $headers->remove('X-Symfony-Version');
        $headers->remove('X-Version');
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 255], // alta prioridade
        ];
    }
}
