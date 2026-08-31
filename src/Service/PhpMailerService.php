<?php

declare(strict_types=1);

namespace App\Service;

use Monolog\Attribute\WithMonologChannel;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Psr\Log\LoggerInterface;

#[WithMonologChannel('mailer')]
final class PhpMailerService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $mailerDsn,
        private readonly string $senderEmail,
        private readonly string $appName,
    ) {
    }

    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        ?string $textBody = null,
    ): bool {
        @set_time_limit(60);

        $this->logger->info('[PhpMailerService] Início da tentativa de envio.', [
            'to' => $this->maskEmail($toEmail),
            'subject' => $subject,
        ]);

        $mail = new PHPMailer(true);

        try {
            $dsn = parse_url($this->mailerDsn);

            if ($dsn === false) {
                $this->logger->error('[PhpMailerService] MAILER_DSN inválido.', [
                    'to' => $this->maskEmail($toEmail),
                ]);

                return false;
            }

            $scheme = strtolower((string) ($dsn['scheme'] ?? 'smtp'));
            $host = (string) ($dsn['host'] ?? '');
            $port = isset($dsn['port']) ? (int) $dsn['port'] : 587;
            $username = isset($dsn['user'])
                ? urldecode((string) $dsn['user'])
                : '';
            $password = isset($dsn['pass'])
                ? urldecode((string) $dsn['pass'])
                : '';

            if ($scheme === 'null') {
                $this->logger->warning(
                    '[PhpMailerService] MAILER_DSN=null://null. O e-mail não foi enviado.',
                    [
                        'to' => $this->maskEmail($toEmail),
                        'subject' => $subject,
                    ],
                );

                return false;
            }

            if ($host === '') {
                $this->logger->error(
                    '[PhpMailerService] Host SMTP ausente no MAILER_DSN.',
                    [
                        'scheme' => $scheme,
                        'port' => $port,
                        'to' => $this->maskEmail($toEmail),
                    ],
                );

                return false;
            }

            if ($username === '') {
                $this->logger->error(
                    '[PhpMailerService] Usuário SMTP ausente no MAILER_DSN.',
                    [
                        'host' => $host,
                        'port' => $port,
                        'to' => $this->maskEmail($toEmail),
                    ],
                );

                return false;
            }

            if ($password === '') {
                $this->logger->error(
                    '[PhpMailerService] Senha SMTP ausente no MAILER_DSN.',
                    [
                        'host' => $host,
                        'port' => $port,
                        'to' => $this->maskEmail($toEmail),
                    ],
                );

                return false;
            }

            if (
                $host === 'smtp.example.com'
                || str_contains($host, 'smtp-do-seu-provedor')
                || str_contains($this->senderEmail, '@example.com')
            ) {
                $this->logger->error(
                    '[PhpMailerService] Configuração SMTP ainda contém valor de exemplo.',
                    [
                        'host' => $host,
                        'port' => $port,
                        'sender' => $this->senderEmail,
                    ],
                );

                return false;
            }

            $this->logger->info('[PhpMailerService] Configuração SMTP carregada.', [
                'scheme' => $scheme,
                'host' => $host,
                'port' => $port,
                'username' => $this->maskEmail($username),
                'from' => $this->senderEmail,
                'to' => $this->maskEmail($toEmail),
            ]);

            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $port;
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $password;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            /*
             * Evita que a requisição web fique travada por tempo indefinido.
             */
            $mail->Timeout = 15;
            $mail->Timelimit = 30;

            /*
             * smtp:// na 587 usa STARTTLS.
             * smtps:// ou porta 465 usa SSL/TLS implícito.
             */
            if ($scheme === 'smtps' || $port === 465) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->SMTPAutoTLS = false;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->SMTPAutoTLS = true;
            }

            /*
             * Diagnóstico temporário: salva a comunicação SMTP no canal
             * "mailer", e nunca imprime informações no navegador.
             */
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;

            $mail->Debugoutput = function (string $message, int $level): void {
                $this->logger->debug('[PhpMailerService] SMTP: '.$message, [
                    'smtp_debug_level' => $level,
                ]);
            };

            $mail->SMTPKeepAlive = false;

            $mail->setFrom($this->senderEmail, $this->appName);
            $mail->addAddress($toEmail, $toName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody ?? trim(strip_tags($htmlBody));

            $this->logger->info('[PhpMailerService] Tentando conexão SMTP.', [
                'host' => $host,
                'port' => $port,
                'encryption' => $mail->SMTPSecure,
            ]);

            $mail->send();

            $this->logger->info(
                '[PhpMailerService] E-mail aceito pelo servidor SMTP.',
                [
                    'to' => $this->maskEmail($toEmail),
                    'subject' => $subject,
                    'host' => $host,
                    'port' => $port,
                ],
            );

            return true;
        } catch (PHPMailerException $exception) {
            $this->logger->error('[PhpMailerService] Falha do PHPMailer.', [
                'to' => $this->maskEmail($toEmail),
                'subject' => $subject,
                'phpmailer_error_info' => $mail->ErrorInfo,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            return false;
        } catch (\Throwable $exception) {
            $this->logger->critical(
                '[PhpMailerService] Falha inesperada durante o envio.',
                [
                    'to' => $this->maskEmail($toEmail),
                    'subject' => $subject,
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ],
            );

            return false;
        }
    }

    private function maskEmail(string $value): string
    {
        if ($value === '') {
            return '[vazio]';
        }

        $parts = explode('@', $value, 2);

        if (count($parts) !== 2) {
            return '[oculto]';
        }

        return mb_substr($parts[0], 0, 2).'***@'.$parts[1];
    }
}
