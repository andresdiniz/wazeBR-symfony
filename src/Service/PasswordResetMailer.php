<?php

declare(strict_types=1);

namespace App\Service;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Psr\Log\LoggerInterface;

final class PasswordResetMailer
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $resendApiKey,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
    }

    public function send(
        string $recipientEmail,
        string $recipientName,
        string $resetUrl,
    ): void {
        $mail = new PHPMailer(true);

        $this->logger->info('Iniciando envio de e-mail de recuperação de senha.', [
            'channel' => 'password_reset_mailer',
            'recipient' => $this->maskEmail($recipientEmail),
            'from' => $this->fromAddress,
            'smtp_host' => 'smtp.resend.com',
            'smtp_port' => 587,
            'encryption' => 'STARTTLS',
        ]);

        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.resend.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'resend';
            $mail->Password = $this->resendApiKey;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->CharSet = 'UTF-8';

            /*
             * Nível 2 registra comandos SMTP e respostas do servidor.
             * Não use DEBUG_LOWLEVEL (4) em produção.
             */
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;

            /*
             * Envia o debug SMTP para Monolog/Symfony logs em vez de
             * imprimir no navegador e quebrar redirects/respostas HTTP.
             */
            $mail->Debugoutput = function (string $message, int $level): void {
                $this->logger->debug('SMTP: '.$message, [
                    'channel' => 'password_reset_mailer',
                    'smtp_debug_level' => $level,
                ]);
            };

            $mail->setFrom($this->fromAddress, $this->fromName);
            $mail->addAddress($recipientEmail, $recipientName);

            $mail->isHTML(true);
            $mail->Subject = 'Redefinição de senha - WazeBR';
            $mail->Body = $this->buildHtmlBody($recipientName, $resetUrl);
            $mail->AltBody = $this->buildTextBody($recipientName, $resetUrl);

            $mail->send();

            $this->logger->info('E-mail de recuperação aceito pelo servidor SMTP.', [
                'channel' => 'password_reset_mailer',
                'recipient' => $this->maskEmail($recipientEmail),
                'from' => $this->fromAddress,
                'smtp_host' => 'smtp.resend.com',
                'smtp_port' => 587,
            ]);
        } catch (Exception $exception) {
            $this->logger->error('Falha no envio de e-mail de recuperação.', [
                'channel' => 'password_reset_mailer',
                'recipient' => $this->maskEmail($recipientEmail),
                'from' => $this->fromAddress,
                'smtp_host' => 'smtp.resend.com',
                'smtp_port' => 587,
                'phpmailer_error' => $mail->ErrorInfo,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            throw new \RuntimeException(
                'Não foi possível enviar o e-mail de recuperação. Consulte os logs do sistema.',
                previous: $exception,
            );
        }
    }

    private function buildTextBody(
        string $recipientName,
        string $resetUrl,
    ): string {
        return sprintf(
            "Olá, %s.\n\n".
            "Recebemos uma solicitação para redefinir a senha da sua conta WazeBR.\n\n".
            "Acesse este link para criar uma nova senha:\n%s\n\n".
            "Se você não solicitou a redefinição, ignore este e-mail. Sua senha não será alterada.",
            $recipientName,
            $resetUrl,
        );
    }

    private function buildHtmlBody(
        string $recipientName,
        string $resetUrl,
    ): string {
        $safeName = htmlspecialchars(
            $recipientName,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        $safeUrl = htmlspecialchars(
            $resetUrl,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        return <<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Redefinição de senha - WazeBR</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#1f2937;">
    <div style="max-width:600px;margin:32px auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
        <div style="padding:24px;background:#146ef5;color:#ffffff;">
            <h1 style="margin:0;font-size:24px;">WazeBR</h1>
        </div>

        <div style="padding:32px;">
            <h2 style="margin-top:0;">Redefinição de senha</h2>

            <p>Olá, {$safeName}.</p>

            <p>Recebemos uma solicitação para redefinir a senha da sua conta WazeBR.</p>

            <p style="margin:28px 0;">
                <a
                    href="{$safeUrl}"
                    style="display:inline-block;padding:12px 20px;background:#146ef5;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold;"
                >
                    Redefinir minha senha
                </a>
            </p>

            <p>Se o botão não funcionar, copie e cole este link no navegador:</p>

            <p style="word-break:break-all;color:#146ef5;">{$safeUrl}</p>

            <p>Se você não solicitou a alteração, ignore este e-mail. Sua senha não será modificada.</p>
        </div>

        <div style="padding:18px 32px;background:#f8fafc;color:#6b7280;font-size:12px;">
            WazeBR — monitoramento e operação.
        </div>
    </div>
</body>
</html>
HTML;
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);

        if (count($parts) !== 2) {
            return '[e-mail inválido]';
        }

        $localPart = $parts[0];
        $visiblePart = mb_substr($localPart, 0, 2);

        return $visiblePart.'***@'.$parts[1];
    }
}
