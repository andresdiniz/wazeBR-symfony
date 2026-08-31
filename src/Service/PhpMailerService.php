<?php

declare(strict_types=1);

namespace App\Service;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Psr\Log\LoggerInterface;

/**
 * Serviço único de envio de e-mail via PHPMailer/SMTP.
 *
 * Substitui os 3 pontos que usavam Symfony Mailer (AuthController::reset,
 * NotificationService::relatório diário) e consolida o PasswordResetMailer
 * que existia no projeto mas nunca era chamado de lugar nenhum — mesmo
 * propósito, um serviço só, reaproveitado pelos 3 fluxos.
 *
 * Lê a mesma variável MAILER_DSN já usada pelo Symfony Mailer (não exige
 * nenhuma variável de ambiente nova) — formato:
 *   smtp://usuario:senha@host:porta       (STARTTLS, ex.: porta 587)
 *   smtps://usuario:senha@host:porta      (TLS implícito, ex.: porta 465)
 *   null://null                           (não envia nada — logs apenas,
 *                                          útil em dev/test sem SMTP configurado)
 */
final class PhpMailerService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $mailerDsn,
        private readonly string $senderEmail,
        private readonly string $appName,
    ) {
    }

    /**
     * Envia um e-mail HTML (com fallback texto automático se não informado).
     * Nunca lança exceção para quem chama — loga o erro e retorna false,
     * para uma falha de e-mail nunca derrubar o fluxo principal (login,
     * criação de conta, etc). Quem chama decide se quer tratar o retorno.
     */
    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        ?string $textBody = null,
    ): bool {
        $dsn = parse_url($this->mailerDsn);
        $scheme = $dsn['scheme'] ?? 'smtp';

        if (str_starts_with($scheme, 'null')) {
            $this->logger->info('[PhpMailerService] MAILER_DSN=null:// — e-mail não enviado (modo silencioso).', [
                'to' => $this->maskEmail($toEmail),
                'subject' => $subject,
            ]);

            return true;
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $dsn['host'] ?? 'localhost';
            $mail->Port       = $dsn['port'] ?? 587;
            $mail->SMTPAuth   = isset($dsn['user']);
            $mail->CharSet    = 'UTF-8';
            $mail->SMTPDebug  = SMTP::DEBUG_OFF;

            if ($mail->SMTPAuth) {
                $mail->Username = urldecode($dsn['user']);
                $mail->Password = urldecode($dsn['pass'] ?? '');
            }

            $mail->SMTPSecure = $scheme === 'smtps'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;

            /*
             * Envia o debug SMTP para os logs do Symfony em vez de
             * imprimir no navegador — evita quebrar redirects/respostas HTTP.
             */
            $mail->Debugoutput = function (string $message, int $level): void {
                $this->logger->debug('[PhpMailerService] SMTP: ' . $message, ['smtp_debug_level' => $level]);
            };

            $mail->setFrom($this->senderEmail, $this->appName);
            $mail->addAddress($toEmail, $toName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody ?? trim(strip_tags($htmlBody));

            $mail->send();

            $this->logger->info('[PhpMailerService] E-mail enviado com sucesso.', [
                'to' => $this->maskEmail($toEmail),
                'subject' => $subject,
                'smtp_host' => $mail->Host,
            ]);

            return true;
        } catch (PHPMailerException|\Throwable $e) {
            $this->logger->error('[PhpMailerService] Falha ao enviar e-mail.', [
                'to' => $this->maskEmail($toEmail),
                'subject' => $subject,
                'phpmailer_error' => $mail->ErrorInfo ?? null,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);

        if (count($parts) !== 2) {
            return '[e-mail inválido]';
        }

        return mb_substr($parts[0], 0, 2) . '***@' . $parts[1];
    }
}
