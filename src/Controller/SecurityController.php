<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/security')]
class SecurityController extends AbstractController
{
    /**
     * Página de acesso negado
     * Exibe mensagem personalizada quando usuá¡£rio não tem permissã££o
     */
    #[Route('/access-denied', name: 'app_security_access_denied', methods: ['GET'])]
    public function accessDenied(Request $request): Response
    {
        // Recupera mensagem de erro da session (se disponí¡£vel)
        $session = $request->getSession();
        $exceptionMessage = $session->get('access_denied_message') ?? null;
        $missingRole = $session->get('access_denied_missing_role') ?? null;
        
        // Limpa os dados da session após leitura
        $session->remove('access_denied_message');
        $session->remove('access_denied_missing_role');
        
        return $this->render('security/access_denied.html.twig', [
            'exception_message' => $exceptionMessage,
            'missing_role' => $missingRole,
        ]);
    }
    
    /**
     * Handler para AccessDeniedException
     * Pode ser configurado no security.yaml como access_denied_handler
     */
    public function accessDeniedHandler(Request $request, AccessDeniedException $exception): Response
    {
        $session = $request->getSession();
        
        // Armazena mensagem de erro na session
        $session->set('access_denied_message', $exception->getMessage());
        
        // Tenta extrair role necessá¡£ria da mensagem de erro
        $message = $exception->getMessage();
        if (preg_match('/ROLE_(\w+)/', $message, $matches)) {
            $session->set('access_denied_missing_role', 'ROLE_' . $matches[1]);
        }
        
        // Redireciona para página de acesso negado
        return $this->redirectToRoute('app_security_access_denied');
    }
}
