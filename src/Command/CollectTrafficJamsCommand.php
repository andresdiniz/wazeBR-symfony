<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WazeApiService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'waze:collect:traffic',
    description: 'Coleta congestionamentos em tempo real da API Waze (substitui wazejobtraficc.php)',
)]
class CollectTrafficJamsCommand extends Command
{
    public function __construct(
        private readonly WazeApiService $wazeApiService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Coleta de Congestionamentos Waze');

        try {
            $result = $this->wazeApiService->collectTrafficJams();
            $io->success(sprintf('Coletados %d congestionamentos, %d novos salvos.', $result['total'], $result['saved']));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Erro na coleta: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
