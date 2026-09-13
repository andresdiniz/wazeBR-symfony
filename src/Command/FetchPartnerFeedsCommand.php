<?php

namespace App\Command;

use App\Entity\Partner;
use App\Entity\WazeAlert;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fetch-partner-feeds',
    description: 'Busca e persiste os feeds dos partners configurados.'
)]
final class FetchPartnerFeedsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $totals = $this->emptyCounters();

        $partners = $this->entityManager
            ->getRepository(Partner::class)
            ->findBy([], ['lastFetchAt' => 'ASC', 'id' => 'ASC']);

        foreach ($partners as $partner) {
            $io->section(sprintf(
                'Processando partner %d — %s',
                $partner->getId(),
                $partner->getName()
            ));

            $counters = $this->emptyCounters();
            $partnerCity = $this->normalizeCity($partner->getCity());

            if ($partnerCity === null) {
                $io->warning(sprintf(
                    'Partner %d (%s) não possui cidade configurada; itens sem cidade serão ignorados.',
                    $partner->getId(),
                    $partner->getName()
                ));
            }

            // O parser real do feed deve chamar processFeedItem() para cada item recebido.
            // O método mantém os contadores e impede city=NULL antes do persist.
            $io->text(sprintf(
                'Resultado: %d processados, %d inseridos, %d atualizados, %d ignorados, %d erros.',
                $counters['processed'],
                $counters['inserted'],
                $counters['updated'],
                $counters['skipped'],
                $counters['errors']
            ));

            $this->addCounters($totals, $counters);
        }

        $io->section('Resumo final');
        $io->table(
            ['Métrica', 'Total'],
            [
                ['Processados', $totals['processed']],
                ['Inseridos', $totals['inserted']],
                ['Atualizados', $totals['updated']],
                ['Ignorados', $totals['skipped']],
                ['Erros', $totals['errors']],
            ]
        );

        $io->success(sprintf(
            'Processamento concluído: %d registro(s) inserido(s), %d atualizado(s), %d ignorado(s).',
            $totals['inserted'],
            $totals['updated'],
            $totals['skipped']
        ));

        return Command::SUCCESS;
    }

    private function processFeedItem(array $item, Partner $partner, array &$counters): void
    {
        $counters['processed']++;

        $city = $this->resolveCity($item, $partner);

        if ($city === null) {
            $counters['skipped']++;
            return;
        }

        $alert = new WazeAlert();
        $alert->setCity($city);
        $alert->setPartner($partner);

        $this->entityManager->persist($alert);
        $counters['inserted']++;
    }

    private function resolveCity(array $item, Partner $partner): ?string
    {
        foreach (['city', 'municipality'] as $key) {
            $city = $this->normalizeCity($item[$key] ?? null);

            if ($city !== null) {
                return $city;
            }
        }

        return $this->normalizeCity($partner->getCity());
    }

    private function normalizeCity(mixed $city): ?string
    {
        if (!is_string($city) && !is_scalar($city)) {
            return null;
        }

        $city = trim((string) $city);

        return $city === '' ? null : $city;
    }

    private function emptyCounters(): array
    {
        return [
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];
    }

    private function addCounters(array &$totals, array $counters): void
    {
        foreach ($totals as $key => $value) {
            $totals[$key] += $counters[$key] ?? 0;
        }
    }
}
