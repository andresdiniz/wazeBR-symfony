<?php

namespace App\EventListener;

use Symfony\Bundle\SecurityBundle\EventListener\LoginThrottlingListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Exception\BadRequestHttpException;

/**
 * Listener to handle bad login requests and redirect back to login page
 */
class LoginBadRequestListener
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    public function __invoke(ExceptionEvent $event, string $eventName): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof BadRequestHttpException) {
            return;
        }

        $request = $event->getRequest();

        // Only handle login requests
        if ($request->attributes->get('_route') !== 'app_login') {
            return;
        }

        // Redirect back to login page with error
        $response = new RedirectResponse(
            $this->urlGenerator->generate('app_login'),
            302
        );

        $response->getSession()?->set('_security.last_error', $exception->getMessage());

        $event->setResponse($response);
    }
}
