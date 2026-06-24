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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'waze:collect',
    description: 'Coleta alertas e congestionamentos do Waze para todos os parceiros ativos.',
)]
class WazeCollectCommand extends Command
{
    private const WAZE_URL = 'https://www.waze.com/row-rtserver/webclient/GeobcRemote.ashx';

    public function __construct(
        private readonly PartnerRepository        $partnerRepo,
        private readonly WazeAlertRepository      $alertRepo,
        private readonly WazeTrafficJamRepository $jamRepo,
        private readonly TenantContext            $tenantContext,
        private readonly HttpClientInterface      $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('partner', 'p', InputOption::VALUE_OPTIONAL,
            'Slug do parceiro (omitir = todos os ativos)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Waze Collect — Multi-Tenant');

        $slugFilter = $input->getOption('partner');

        $partners = $slugFilter
            ? array_filter(
                $this->partnerRepo->findActivePartners(),
                fn (Partner $p) => $p->getSlug() === $slugFilter,
            )
            : $this->partnerRepo->findActivePartners();

        if (empty($partners)) {
            $io->warning('Nenhum parceiro ativo encontrado.');
            return Command::SUCCESS;
        }

        foreach ($partners as $partner) {
            $this->tenantContext->setPartner($partner);
            $io->section("Parceiro: {$partner->getName()} [{$partner->getSlug()}]");

            if (!$partner->getBbox()) {
                $io->warning('Bbox não configurada. Pulando.');
                continue;
            }

            try {
                $data = $this->fetchWaze($partner->getBbox());
                $alerts = $this->persistAlerts($partner, $data['alerts'] ?? []);
                $jams   = $this->persistJams($partner, $data['jams'] ?? []);
                $io->success("Alertas: {$alerts} novos | Jams: {$jams} novos");
            } catch (\Throwable $e) {
                $io->error("Erro [{$partner->getSlug()}]: " . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }

    private function fetchWaze(string $bbox): array
    {
        [$latMin, $lngMin, $latMax, $lngMax] = explode(',', $bbox);

        $response = $this->httpClient->request('GET', self::WAZE_URL, [
            'query' => [
                'left'   => $lngMin,
                'right'  => $lngMax,
                'bottom' => $latMin,
                'top'    => $latMax,
                'ma'     => 600,
                'mj'     => 300,
                'mt'     => 0,
                'types'  => 'alerts,traffic',
            ],
            'timeout' => 30,
        ]);

        return $response->toArray();
    }

    private function persistAlerts(Partner $partner, array $rawAlerts): int
    {
        $count = 0;
        foreach ($rawAlerts as $raw) {
            $wazeId = $raw['uuid'] ?? null;
            if (!$wazeId) continue;

            $existing = $this->alertRepo->findOneBy(['wazeId' => $wazeId, 'partner' => $partner]);
            if ($existing) continue;

            $alert = (new WazeAlert())
                ->setPartner($partner)
                ->setWazeId($wazeId)
                ->setType($raw['type'] ?? 'UNKNOWN')
                ->setSubtype($raw['subtype'] ?? null)
                ->setLatitude((float) ($raw['location']['y'] ?? 0))
                ->setLongitude((float) ($raw['location']['x'] ?? 0))
                ->setStreet($raw['street'] ?? null)
                ->setCity($raw['city'] ?? null)
                ->setCountry($raw['country'] ?? 'BR')
                ->setReliability((int) ($raw['reliability'] ?? 0))
                ->setConfidence((int) ($raw['confidence'] ?? 0))
                ->setReportRating((int) ($raw['reportRating'] ?? 0))
                ->setPubMillis((int) ($raw['pubMillis'] ?? 0));

            $this->alertRepo->save($alert, false);
            $count++;
        }

        if ($count > 0) {
            $this->alertRepo->getEntityManager()->flush();
        }

        return $count;
    }

    private function persistJams(Partner $partner, array $rawJams): int
    {
        $count = 0;
        foreach ($rawJams as $raw) {
            $wazeId = $raw['uuid'] ?? null;
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
                ->setPubMillis((int) ($raw['pubMillis'] ?? 0));

            $this->jamRepo->save($jam, false);
            $count++;
        }

        if ($count > 0) {
            $this->jamRepo->getEntityManager()->flush();
        }

        return $count;
    }
}
