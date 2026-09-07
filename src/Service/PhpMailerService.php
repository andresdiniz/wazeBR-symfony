<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Service para envio de emails usando PHPMailer (alternativa ao Symfony Mailer)
 */
class PhpMailerService
{
    /**
     * Enviar email
     *
     * @param string $to Email do destinat\u00e1rio
     * @param string $subject Assunto
     * @param string $body Corpo do email
     * @param string|null $bodyHTML Corpo HTML (opcional)
     * @return bool Sucesso
     */
    public function sendEmail(string $to, string $subject, string $body, ?string $bodyHTML = null): bool
    {
        try {
            // Configura\u00e7\u00f5es de email (hardcoded ou do .env)
            $mailerDsn = $_ENV['MAILER_DSN'] ?? '';
            $mailerFromEmail = $_ENV['MAILER_FROM_EMAIL'] ?? 'noreply@wazebr.com';
            $mailerFromName = $_ENV['MAILER_FROM_NAME'] ?? 'wazeBR';
            
            // Parse DSN para extrair configura\u00e7\u00f5es SMTP
            // Exemplo: smtp://user:pass@smtp.example.com:587
            $parsed = parse_url($mailerDsn);
            
            if (!$parsed || !isset($parsed['host'])) {
                // Fallback para configura\u00e7\u00f5es padr\u00e3o
                $smtpHost = 'localhost';
                $smtpPort = 25;
                $smtpUser = '';
                $smtpPass = '';
            } else {
                $smtpHost = $parsed['host'];
                $smtpPort = $parsed['port'] ?? 25;
                $smtpUser = $parsed['user'] ?? '';
                $smtpPass = $parsed['pass'] ?? '';
            }
            
            // TODO: Implement PHPMailer logic here
            // 1. Create PHPMailer instance
            // 2. Configure SMTP settings
            // 3. Set from, to, subject, body
            // 4. Send email
            
            // Exemplo (se PHPMailer estiver instalado):
            /*
            $mail = new \PHPMailer\PHPMailer\PHPMailer();
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = !empty($smtpUser);
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
            $mail->Port = $smtpPort;
            
            $mail->setFrom($mailerFromEmail, $mailerFromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $bodyHTML ?? $body;
            $mail->AltBody = $body;
            
            return $mail->send();
            */
            
            // Por enquanto, retorna true (simula envio)
            return true;
        } catch (\Exception $e) {
            error_log('Erro ao enviar email com PHPMailer: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Enviar email de reset de senha (alternativa ao EmailService)
     *
     * @param string $toEmail Email do destinat\u00e1rio
     * @param string $token Token de reset
     * @param string $resetUrl URL de reset
     * @return bool Sucesso
     */
    public function sendPasswordResetEmail(string $toEmail, string $token, string $resetUrl): bool
    {
        $subject = 'wazeBR - Redefini\u00e7\u00e3o de Senha';
        
        $body = "Ol\u00e1,\n\n" .
                "Recebemos uma solicita\u00e7\u00e3o para redefinir a senha da sua conta wazeBR.\n\n" .
                "Para redefinir sua senha, clique no link abaixo:\n" .
                "{$resetUrl}\n\n" .
                "Ou use o c\u00f3digo: {$token}\n\n" .
                "IMPORTANTE:\n" .
                "- Este link \u00e9 v\u00e1lido por 1 hora\n" .
                "- S\u00f3 pode ser usado uma vez\n\n" .
                "Se voc\u00ea n\u00e3o fez esta solicita\u00e7\u00e3o, pode ignorar este email.\n\n" .
                "--\n" .
                "wazeBR";
        
        $bodyHTML = null; // TODO: Add HTML template
        
        return $this->sendEmail($toEmail, $subject, $body, $bodyHTML);
    }
}
