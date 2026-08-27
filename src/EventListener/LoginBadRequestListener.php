<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Trata POSTs malformados em /login (campo "email" ausente do corpo da
 * requisição) sem deixar o BadRequestHttpException estourar como página
 * de erro.
 *
 * Isso acontece tipicamente com tráfego de bot/scanner de segurança
 * varrendo formulários de login às cegas (tentando "username" em vez de
 * "email", ou mandando corpo vazio) — não é erro do usuário. O
 * FormLoginAuthenticator do Symfony 7 lança BadRequestHttpException
 * ("The key \"email\" must be a string, \"NULL\" given") quando o campo
 * simplesmente não vem na requisição, antes mesmo de tentar autenticar.
 *
 * Prioridade alta (10) para interceptar antes do listener de erro
 * padrão do Symfony renderizar qualquer página de exceção.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
class LoginBadRequestListener
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof BadRequestHttpException) {
            return;
        }

        if ($event->getRequest()->getPathInfo() !== '/login') {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('auth_login'),
        ));
    }
}
