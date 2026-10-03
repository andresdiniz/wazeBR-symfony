<?php
// test_email.php - Script de teste de e-mail

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;

require_once 'vendor/autoload.php';

// Carregar .env
$dotenv = new \Symfony\Component\Dotenv\Dotenv();
$dotenv->load('.env');

echo "MAILER_DSN: " . getenv('MAILER_DSN') . "\n";
echo "MAILER_FROM_EMAIL: " . getenv('MAILER_FROM_EMAIL') . "\n";
echo "MAILER_FROM_NAME: " . getenv('MAILER_FROM_NAME') . "\n\n";

try {
    $transport = Transport::fromDsn(getenv('MAILER_DSN'));
    $mailer = new Mailer($transport);

    $email = (new Email())
        ->from(getenv('MAILER_FROM_EMAIL'))
        ->to('seu-email-teste@exemplo.com')  // <-- COLOQUE SEU E-MAIL AQUI
        ->subject('Teste de e-mail - WazeBR')
        ->text('Se recebeu este e-mail, o envio está funcionando!');

    $mailer->send($email);
    echo "E-mail enviado com sucesso!\n";
} catch (\Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
