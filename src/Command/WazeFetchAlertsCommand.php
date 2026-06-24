<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\MonitoredLinkRepository;
use App\Service\WazeFeedService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Coleta alertas Waze de todos os MonitoredLink(type=feed) ativos.
 *
 * Uso:
 *   php bin/console app:waze:fetch-alerts
 *   php bin/console app:waze:fetch-alerts --partner=pbh
 *
 * Cron (a cada 2 min):
 *   * /2 * * * * cd /caminho/projeto && php bin/console app:waze:fetch-alerts >> var/log/waze_fetch.log 2>&1
 */
#[AsCommand(
    name: 'app:waze:fetch-alerts',
    description: 'Coleta alertas Waze de todos os feeds MonitoredLink ativos e salva no banco.',
)]
class WazeFetchAlertsCommand extends Command
{
    public function __construct(
        private readonly MonitoredLinkRepository $linkRepo,
        private readonly WazeFeedService         $feedService,
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
        $io         = new SymfonyStyle($input, $output);
        $partnerSlug = $input->getOption('partner');
        $dryRun      = (bool) $input->getOption('dry-run');

        $io->title('Waze Feed — Coleta de Alertas');

        if ($dryRun) {
            $io->warning('Modo DRY-RUN: nenhum dado será salvo.');
        }

        // Busca todos os links feed ativos
        $links = $this->linkRepo->findActiveWazeFeeds();

        if (empty($links)) {
            $io->warning('Nenhum MonitoredLink ativo com type=feed encontrado.');
            return Command::SUCCESS;
        }

        // Filtra por parceiro se --partner foi informado
        if ($partnerSlug !== null) {
            $links = array_filter(
                $links,
                fn($l) => $l->getPartner()?->getSlug() === $partnerSlug
            );

            if (empty($links)) {
                $io->error("Nenhum feed encontrado para o parceiro: {$partnerSlug}");
                return Command::FAILURE;
            }
        }

        $totalAlerts = 0;
        $totalErrors = 0;
        $rows        = [];

        foreach ($links as $link) {
            $partnerName = $link->getPartner()?->getName() ?? 'desconhecido';

            try {
                if ($dryRun) {
                    $io->writeln("[DRY-RUN] Pulando: {$link->getName()} ({$link->getUrl()})");
                    $rows[] = [$partnerName, $link->getName(), 'dry-run', '-'];
                    continue;
                }

                $count = $this->feedService->fetchAndPersist($link);
                $link->markSuccess($count);
                $this->em->flush();

                $totalAlerts += $count;
                $rows[] = [$partnerName, $link->getName(), "✅ {$count} alertas", '-'];

            } catch (\Throwable $e) {
                $totalErrors++;
                $link->markError($e->getMessage());
                $this->em->flush();

                $rows[] = [$partnerName, $link->getName(), '❌ erro', $e->getMessage()];
                $io->error("Erro em [{$link->getName()}]: " . $e->getMessage());
            }
        }

        $io->table(
            ['Parceiro', 'Feed', 'Resultado', 'Erro'],
            $rows
        );

        $io->success("Concluído: {$totalAlerts} alertas salvos, {$totalErrors} erros.");

        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
