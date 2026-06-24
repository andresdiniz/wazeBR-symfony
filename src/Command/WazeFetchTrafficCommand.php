<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\MonitoredLinkRepository;
use App\Service\WazeTrafficFeedService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Coleta jams de tr\u00e1fego dos feeds TVT ativos.
 *
 * Uso:
 *   php bin/console app:waze:fetch-traffic
 *   php bin/console app:waze:fetch-traffic --partner=pbh
 *   php bin/console app:waze:fetch-traffic --dry-run
 *
 * Cron (a cada 2 min):
 *   * /2 * * * * cd /caminho/projeto && php bin/console app:waze:fetch-traffic >> var/log/waze_traffic.log 2>&1
 */
#[AsCommand(
    name: 'app:waze:fetch-traffic',
    description: 'Coleta jams de tr\u00e1fego Waze (feeds TVT) de todos os MonitoredLink ativos.',
)]
class WazeFetchTrafficCommand extends Command
{
    public function __construct(
        private readonly MonitoredLinkRepository $linkRepo,
        private readonly WazeTrafficFeedService  $trafficService,
        private readonly EntityManagerInterface  $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner', 'p', InputOption::VALUE_OPTIONAL,
                'Slug do parceiro (ex: pbh). Omitir para processar todos.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Simula a coleta sem salvar no banco.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io          = new SymfonyStyle($input, $output);
        $partnerSlug = $input->getOption('partner');
        $dryRun      = (bool) $input->getOption('dry-run');

        $io->title('Waze TVT \u2014 Coleta de Tr\u00e1fego');

        if ($dryRun) {
            $io->warning('Modo DRY-RUN: nenhum dado ser\u00e1 salvo.');
        }

        $links = $this->linkRepo->findActiveTrafficFeeds();

        if (empty($links)) {
            $io->warning('Nenhum MonitoredLink ativo com type=traffic encontrado.');
            return Command::SUCCESS;
        }

        if ($partnerSlug !== null) {
            $links = array_filter(
                $links,
                fn($l) => $l->getPartner()?->getSlug() === $partnerSlug
            );
            if (empty($links)) {
                $io->error("Nenhum feed traffic encontrado para: {$partnerSlug}");
                return Command::FAILURE;
            }
        }

        $totalJams   = 0;
        $totalErrors = 0;
        $rows        = [];

        foreach ($links as $link) {
            $partnerName = $link->getPartner()?->getName() ?? 'desconhecido';

            try {
                if ($dryRun) {
                    $rows[] = [$partnerName, $link->getName(), 'dry-run', '-'];
                    continue;
                }

                $count = $this->trafficService->fetchAndPersist($link);
                $link->markSuccess($count);
                $this->em->flush();

                $totalJams += $count;
                $rows[] = [$partnerName, $link->getName(), "\u2705 {$count} jams", '-'];

            } catch (\Throwable $e) {
                $totalErrors++;
                $link->markError($e->getMessage());
                $this->em->flush();

                $rows[] = [$partnerName, $link->getName(), '\u274c erro', $e->getMessage()];
                $io->error("Erro em [{$link->getName()}]: " . $e->getMessage());
            }
        }

        $io->table(['Parceiro', 'Feed', 'Resultado', 'Erro'], $rows);
        $io->success("Conclu\u00eddo: {$totalJams} jams salvos, {$totalErrors} erros.");

        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
