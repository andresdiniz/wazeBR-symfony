<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment as TwigEnvironment;

/**
 * Service para envio de emails
 */
class EmailService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TwigEnvironment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Enviar email de reset de senha
     *
     * @param string $toEmail Email do destinat\u00e1rio
     * @param string $token Token de reset
     * @return bool Sucesso do envio
     */
    public function sendPasswordResetEmail(string $toEmail, string $token): bool
    {
        try {
            // Configura\u00e7\u00f5es de email (hardcoded ou do .env)
            $mailerFromEmail = $_ENV['MAILER_FROM_EMAIL'] ?? 'noreply@wazebr.com';
            $mailerFromName = $_ENV['MAILER_FROM_NAME'] ?? 'wazeBR';
            
            // Gerar URL de reset
            $resetUrl = $this->urlGenerator->generate(
                'app_reset_password_reset',
                ['token' => $token],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            // Renderizar template HTML
            $htmlContent = $this->twig->render('emails/reset_password.html.twig', [
                'token' => $token,
                'resetUrl' => $resetUrl,
            ]);

            // Renderizar template texto puro (fallback)
            $textContent = $this->generateTextContent($toEmail, $token, $resetUrl);

            // Criar email
            $email = (new \Symfony\Component\Mailer\Mime\Email())
                ->from(new Address($mailerFromEmail, $mailerFromName))
                ->to($toEmail)
                ->subject('wazeBR - Redefini\u00e7\u00e3o de Senha')
                ->html($htmlContent)
                ->text($textContent)
                ->addHeader('X-Priority', '1'); // High priority

            // Enviar email
            $this->mailer->send($email);

            return true;
        } catch (\Exception $e) {
            // Log error
            error_log('Erro ao enviar email de reset: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gerar conte\u00fado em texto puro
     */
    private function generateTextContent(string $toEmail, string $token, string $resetUrl): string
    {
        return <<<TEXT
        wazeBR - Redefini\u00e7\u00e3o de Senha
        ====================================

        Ol\u00e1,

        Recebemos uma solicita\u00e7\u00e3o para redefinir a senha da sua conta wazeBR.

        Para redefinir sua senha, clique no link abaixo:
        {$resetUrl}

        Ou use o c\u00f3digo: {$token}

        IMPORTANTE:
        - Este link \u00e9 v\u00e1lido por 1 hora
        - S\u00f3 pode ser usado uma vez
        - N\u00e3o compartilhe este link com ningu\u00e9m

        Se voc\u00ea n\u00e3o fez esta solicita\u00e7\u00e3o, pode ignorar este email com seguran\u00e7a.

        Precisa de ajuda? Entre em contato: suporte@wazebr.com

        --
        \u00a9 " . date('Y') . " wazeBR. Todos os direitos reservados.
        Desenvolvido com \u2764 no Brasil
        TEXT;
    }

    /**
     * Enviar email de boas-vindas
     */
    public function sendWelcomeEmail(string $toEmail, string $userName): bool
    {
        try {
            $mailerFromEmail = $_ENV['MAILER_FROM_EMAIL'] ?? 'noreply@wazebr.com';
            $mailerFromName = $_ENV['MAILER_FROM_NAME'] ?? 'wazeBR';
            
            $htmlContent = $this->twig->render('emails/welcome.html.twig', [
                'userName' => $userName,
            ]);

            $email = (new \Symfony\Component\Mailer\Mime\Email())
                ->from(new Address($mailerFromEmail, $mailerFromName))
                ->to($toEmail)
                ->subject('Bem-vindo ao wazeBR!')
                ->html($htmlContent);

            $this->mailer->send($email);

            return true;
        } catch (\Exception $e) {
            error_log('Erro ao enviar email de boas-vindas: ' . $e->getMessage());
            return false;
        }
    }
}
