<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\TenantContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Intercepta /api/externa/* e resolve o tenant via X-Api-Token.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
class TenantTokenListener
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/externa')) {
            return;
        }

        $token = $request->headers->get('X-Api-Token');

        if (!$token) {
            $event->setResponse(new JsonResponse(
                ['error' => 'Header X-Api-Token obrigatório.'],
                JsonResponse::HTTP_UNAUTHORIZED,
            ));
            return;
        }

        $partner = $this->tenantContext->resolveFromToken($token);

        if ($partner === null) {
            $event->setResponse(new JsonResponse(
                ['error' => 'Token inválido ou parceiro inativo.'],
                JsonResponse::HTTP_FORBIDDEN,
            ));
        }
    }
}
