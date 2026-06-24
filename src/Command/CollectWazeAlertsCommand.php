<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WazeApiService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'waze:collect:alerts',
    description: 'Coleta alertas em tempo real da API Waze (substitui wazejob.php)',
)]
class CollectWazeAlertsCommand extends Command
{
    public function __construct(
        private readonly WazeApiService $wazeApiService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_OPTIONAL, 'Caminho para salvar JSON', null)
             ->addOption('xml',  null, InputOption::VALUE_OPTIONAL, 'Caminho para salvar XML',  null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Coleta de Alertas Waze');

        try {
            $result = $this->wazeApiService->collectAlerts();
            $io->success(sprintf('Coletados %d alertas da API, %d novos salvos.', $result['total'], $result['saved']));

            if ($jsonPath = $input->getOption('json')) {
                $this->wazeApiService->generateJson($jsonPath);
                $io->writeln("<info>JSON gerado em: {$jsonPath}</info>");
            }

            if ($xmlPath = $input->getOption('xml')) {
                $this->wazeApiService->generateXml($xmlPath);
                $io->writeln("<info>XML gerado em: {$xmlPath}</info>");
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Erro na coleta: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
