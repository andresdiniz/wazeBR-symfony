<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

#[AsCommand(name: 'app:test-email', description: 'Testa o transporte do Symfony Mailer para um destinatário explícito.') ]
final class TestEmailCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM_EMAIL)%')] private readonly string $fromEmail,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $fromName,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('recipient', InputArgument::REQUIRED, 'E-mail que receberá o teste');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $recipient = trim((string) $input->getArgument('recipient'));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $io->error('Informe um e-mail válido.');
            return Command::INVALID;
        }
        try {
            $this->mailer->send((new Email())->from(new Address($this->fromEmail, $this->fromName))->to($recipient)->subject('Teste WazeBR — Symfony Mailer')->text('Teste de envio solicitado pelo comando app:test-email.'));
            $io->success('Envio solicitado ao Symfony Mailer. Confirme o recebimento e verifique se o transporte não é null://null.');
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error('Falha ao solicitar envio. Consulte os logs do transporte; não compartilhe credenciais SMTP.');
            return Command::FAILURE;
        }
    }
}
