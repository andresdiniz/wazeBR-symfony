<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WeatherObservationFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:weather:fetch-observations',
    description: 'Busca observações climáticas atuais (Open-Meteo) para todas as WeatherLocations ativas.',
)]
final class FetchWeatherObservationsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WeatherObservationFetcher $observationFetcher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->resetStaleConnection();

        $io->title('Coleta de clima');

        try {
            // O WeatherObservationFetcher já notifica as TVs dos
            // partners afetados ao final de fetchAndStoreObservations().
            $result = $this->observationFetcher->fetchAndStoreObservations();
        } catch (\Throwable $e) {
            $io->error('Falha ao buscar observações: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->table(
            ['Métrica', 'Valor'],
            [
                ['Locations processadas', $result['total']],
                ['Observações inseridas', $result['inserted']],
                ['Ignoradas (duplicadas)', $result['skipped']],
                ['Falhas',                 $result['failed']],
            ],
        );

        if ($result['failed'] > 0) {
            $io->warning('Algumas locations falharam. Confira o log.');

            return Command::FAILURE;
        }

        $io->success('Coleta concluída.');

        return Command::SUCCESS;
    }

    private function resetStaleConnection(): void
    {
        $connection = $this->entityManager->getConnection();

        try {
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            $connection->close();
        }
    }
}
