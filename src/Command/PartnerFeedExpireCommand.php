<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\CifsFeedBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Expira eventos CIFS antigos (endTime ja passou).
 *
 * Cron sugerido (mesmo padrao dos fetchers), a cada 15 minutos:
 *   0,15,30,45 * * * *  php /caminho/projeto/bin/console app:partner-feed:expire --env=prod
 */
#[AsCommand(
    name: 'app:partner-feed:expire',
    description: 'Desativa eventos do partner feed cujo endTime ja passou'
)]
class PartnerFeedExpireCommand extends Command
{
    public function __construct(
        private readonly CifsFeedBuilder $feedBuilder,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Partner Feed - Expiracao de eventos antigos');

        try {
            $count = $this->feedBuilder->expireOldEvents();
            $io->success(sprintf('%d eventos foram desativados.', $count));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->logger->error('Erro ao expirar eventos do partner feed', ['exception' => $e]);
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}