<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\MonitoredLinkRepository;
use App\Service\WazeTrafficFeedService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Coleta todos os feeds TVT ativos e salva snapshots no banco.
 *
 * Uso:
 *   php bin/console app:waze:fetch-traffic
 *   php bin/console app:waze:fetch-traffic --partner=prefeitura-bh
 */
#[AsCommand(
    name: 'app:waze:fetch-traffic',
    description: 'Coleta feeds TVT do Waze (routes/subRoutes) e salva snapshots',
)]
class WazeFetchTrafficCommand extends Command
{
    public function __construct(
        private readonly MonitoredLinkRepository $linkRepo,
        private readonly WazeTrafficFeedService  $feedService,
        private readonly EntityManagerInterface  $em,
        private readonly LoggerInterface         $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner', 'p', InputOption::VALUE_OPTIONAL, 'Slug do parceiro (omitir = todos ativos)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Busca o JSON mas NÃO salva nada no banco');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $partnerSlug = $input->getOption('partner');
        $dryRun     = $input->getOption('dry-run');

        $io->title('Waze TVT — Coleta de Tráfego (routes/subRoutes)');

        if ($dryRun) {
            $io->warning('Modo DRY-RUN: nenhum dado será salvo.');
        }

        // Buscar links ativos do tipo traffic
        $links = $this->linkRepo->findActiveByType('traffic', $partnerSlug);

        if (empty($links)) {
            $io->warning('Nenhum link de tráfego ativo encontrado' . ($partnerSlug ? " para partner={$partnerSlug}" : '') . '.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Links encontrados: <info>%d</info>', count($links)));
        $io->newLine();

        $totalRoutes = 0;
        $errors      = 0;

        foreach ($links as $link) {
            $io->text(sprintf(
                '🔗 <info>%s</info> [%s] → %s',
                $link->getName(),
                $link->getPartner()->getSlug(),
                $link->getUrl()
            ));

            if ($dryRun) {
                // Apenas busca e mostra estrutura
                try {
                    $response = (new \Symfony\Component\HttpClient\HttpClient())->create()->request(
                        'GET', $link->getUrl(), ['timeout' => 20]
                    );
                    $data = $response->toArray();
                    $io->text(sprintf(
                        '   ✓ HTTP 200 | routes: %d | updateTime: %s | area: %s',
                        count($data['routes'] ?? []),
                        $data['updateTime'] ?? 'N/A',
                        $data['areaName'] ?? 'N/A'
                    ));
                    $totalRoutes += count($data['routes'] ?? []);
                } catch (\Throwable $e) {
                    $io->error(sprintf('   ✗ Erro: %s', $e->getMessage()));
                    $errors++;
                }
                continue;
            }

            try {
                $count = $this->feedService->fetchAndPersist($link);

                // Atualizar metadata do link
                $link->setLastFetchedAt(new \DateTimeImmutable());
                $link->setLastFetchCount($count);
                $link->setLastErrorMessage(null);
                $this->em->flush();

                $io->text(sprintf('   ✓ %d rotas salvas', $count));
                $totalRoutes += $count;

            } catch (\Throwable $e) {
                $this->logger->error('[WazeTVT] Falha ao coletar link', [
                    'link'  => $link->getName(),
                    'error' => $e->getMessage(),
                ]);

                $link->setLastErrorMessage(mb_substr($e->getMessage(), 0, 255));
                $this->em->flush();

                $io->error(sprintf('   ✗ %s', $e->getMessage()));
                $errors++;
            }
        }

        $io->newLine();
        if ($errors > 0) {
            $io->warning(sprintf('Concluído com %d erro(s). Total de rotas: %d', $errors, $totalRoutes));
            return Command::FAILURE;
        }

        $io->success(sprintf('Concluído. Total de rotas salvas: %d', $totalRoutes));
        return Command::SUCCESS;
    }
}
