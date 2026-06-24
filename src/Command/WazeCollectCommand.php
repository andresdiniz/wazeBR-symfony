<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Partner;
use App\Entity\WazeAlert;
use App\Entity\WazeTrafficJam;
use App\Repository\PartnerRepository;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeTrafficJamRepository;
use App\Service\TenantContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:waze:collect',
    description: 'Coleta alertas e congestionamentos do Waze para todos os parceiros ativos.',
)]
class WazeCollectCommand extends Command
{
    private const WAZE_BASE_URL = 'https://www.waze.com/row-partnerhub-api/partners/11/waze-feeds';

    public function __construct(
        private readonly PartnerRepository       $partnerRepository,
        private readonly WazeAlertRepository     $alertRepo,
        private readonly WazeTrafficJamRepository $jamRepo,
        private readonly TenantContext           $tenantContext,
        private readonly HttpClientInterface     $httpClient,
        private readonly LoggerInterface         $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner', 'p', InputOption::VALUE_OPTIONAL, 'Slug do parceiro (omita para coletar todos)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula a coleta sem persistir');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $slug   = $input->getOption('partner');

        $io->title('WazeBR — Coleta de Alertas e Congestionamentos');

        $partners = $slug
            ? array_filter($this->partnerRepository->findActivePartners(), fn ($p) => $p->getSlug() === $slug)
            : $this->partnerRepository->findActivePartners();

        if (empty($partners)) {
            $io->warning('Nenhum parceiro ativo encontrado.');
            return Command::SUCCESS;
        }

        foreach ($partners as $partner) {
            $this->tenantContext->setPartner($partner);
            $io->section("[{$partner->getSlug()}] {$partner->getName()}");

            try {
                [$alerts, $jams] = $this->fetchWazeData($partner);

                $alertCount = $this->persistAlerts($partner, $alerts, $dryRun);
                $jamCount   = $this->persistJams($partner, $jams, $dryRun);

                $io->success("Alertas: {$alertCount} | Congestionamentos: {$jamCount}" . ($dryRun ? ' (dry-run)' : ''));

                $this->logger->info('waze.collect.success', [
                    'partner'     => $partner->getSlug(),
                    'alerts'      => $alertCount,
                    'jams'        => $jamCount,
                ]);
            } catch (\Throwable $e) {
                $io->error("Erro em [{$partner->getSlug()}]: " . $e->getMessage());
                $this->logger->error('waze.collect.error', [
                    'partner' => $partner->getSlug(),
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return Command::SUCCESS;
    }

    private function fetchWazeData(Partner $partner): array
    {
        $bbox = $partner->getBbox() ?? '';

        $url = self::WAZE_BASE_URL . '?' . http_build_query([
            'types'  => 'alerts,traffic',
            'format' => 'JSON',
            'bbox'   => $bbox,
        ]);

        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 30,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $data = $response->toArray();

        return [
            $data['alerts']  ?? [],
            $data['jams']    ?? [],
        ];
    }

    private function persistAlerts(Partner $partner, array $alerts, bool $dryRun): int
    {
        $count = 0;

        foreach ($alerts as $raw) {
            $wazeId = (string) ($raw['uuid'] ?? $raw['id'] ?? '');
            if (!$wazeId) continue;

            $existing = $this->alertRepo->findOneBy(['wazeId' => $wazeId, 'partner' => $partner]);
            if ($existing) continue;

            $alert = (new WazeAlert())
                ->setPartner($partner)
                ->setWazeId($wazeId)
                ->setType((string) ($raw['type'] ?? ''))
                ->setSubtype($raw['subtype'] ?? null)
                ->setLatitude((float) ($raw['location']['y'] ?? 0))
                ->setLongitude((float) ($raw['location']['x'] ?? 0))
                ->setStreet($raw['street'] ?? null)
                ->setCity($raw['city'] ?? null)
                ->setCountry($raw['country'] ?? 'BR')
                ->setReliability((int) ($raw['reliability'] ?? 0))
                ->setConfidence((int) ($raw['confidence'] ?? 0))
                ->setReportRating((int) ($raw['reportRating'] ?? 0))
                ->setPubMillis((int) ($raw['pubMillis'] ?? (time() * 1000)));

            if (!$dryRun) {
                $this->alertRepo->save($alert, false);
            }
            $count++;
        }

        if (!$dryRun && $count > 0) {
            $this->alertRepo->getEntityManager()->flush();
        }

        return $count;
    }

    private function persistJams(Partner $partner, array $jams, bool $dryRun): int
    {
        $count = 0;

        foreach ($jams as $raw) {
            $wazeId = (string) ($raw['uuid'] ?? $raw['id'] ?? '');
            if (!$wazeId) continue;

            $existing = $this->jamRepo->findOneBy(['wazeId' => $wazeId, 'partner' => $partner]);
            if ($existing) continue;

            $jam = (new WazeTrafficJam())
                ->setPartner($partner)
                ->setWazeId($wazeId)
                ->setStreet($raw['street'] ?? null)
                ->setCity($raw['city'] ?? null)
                ->setLevel((int) ($raw['level'] ?? 0))
                ->setSpeedKmh((float) ($raw['speedKMH'] ?? 0))
                ->setLength((float) ($raw['length'] ?? 0))
                ->setDelay((int) ($raw['delay'] ?? 0))
                ->setLine($raw['line'] ?? [])
                ->setPubMillis((int) ($raw['pubMillis'] ?? (time() * 1000)));

            if (!$dryRun) {
                $this->jamRepo->save($jam, false);
            }
            $count++;
        }

        if (!$dryRun && $count > 0) {
            $this->jamRepo->getEntityManager()->flush();
        }

        return $count;
    }
}
