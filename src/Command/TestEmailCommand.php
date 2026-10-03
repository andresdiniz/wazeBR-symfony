<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[AsCommand(
    name: 'app:test-email',
    description: 'Testa envio de e-mail',
)]
class TestEmailCommand extends Command
{
    public function __construct(
        private MailerInterface $mailer
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->info('Testando envio de e-mail...');

        try {
            $email = (new Email())
                ->from('no-reply@trafik.acheireviews.com.br')
                ->to('andresoaresdiniz201218@gmail.com')  // <-- TROQUE PELO SEU E-MAIL
                ->subject('Teste WazeBR')
                ->text('Se recebeu este e-mail, está funcionando!');

            $this->mailer->send($email);

            $io->success('E-mail enviado com sucesso!');
        } catch (\Exception $e) {
            $io->error('ERRO: ' . $e->getMessage());
        }

        return Command::SUCCESS;
    }
}
