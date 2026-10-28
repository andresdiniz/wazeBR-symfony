<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class LocaleSubscriber implements EventSubscriberInterface
{
    private const LOCALES = ['pt_BR', 'en', 'es'];

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
            KernelEvents::RESPONSE => ['onKernelResponse', -10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (str_starts_with($request->getPathInfo(), '/_')) {
            return;
        }

        $query = $request->query->all();
        $selected = $query['_locale'] ?? null;
        $locale = 'pt_BR';

        if ($request->hasPreviousSession()) {
            $stored = $request->getSession()->get('_locale');
            if (is_string($stored) && in_array($stored, self::LOCALES, true)) {
                $locale = $stored;
            }
        }

        if (is_string($selected) && in_array($selected, self::LOCALES, true)) {
            $locale = $selected;
            if ($request->hasSession()) {
                $request->getSession()->set('_locale', $locale);
            }
        }

        $request->attributes->set('_locale', $locale);
        $request->setLocale($locale);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (str_starts_with($request->getPathInfo(), '/_')) {
            return;
        }

        $response = $event->getResponse();
        if (!str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
            return;
        }

        $response->headers->set('Content-Language', str_replace('_', '-', $request->getLocale()));
        $response->setVary('Cookie', false);
        $response->setPrivate();
    }
}
