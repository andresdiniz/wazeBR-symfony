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
use Doctrine\ORM\EntityManagerInterface;
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
    description: 'Coleta alertas e congestionamentos Waze para todos os parceiros ativos.',
)]
class WazeCollectCommand extends Command
{
    private const WAZE_URL = 'https://www.waze.com/row-partnerhub-api/partners/11/waze-feeds/eb434e4b-c2e4-4ca7-a2c5-b56f5e9bffc1?format=1&types=alerts,traffic';

    public function __construct(
        private readonly PartnerRepository        $partnerRepository,
        private readonly WazeAlertRepository      $alertRepository,
        private readonly WazeTrafficJamRepository $jamRepository,
        private readonly EntityManagerInterface   $em,
        private readonly HttpClientInterface      $httpClient,
        private readonly TenantContext            $tenantContext,
        private readonly LoggerInterface          $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('partner', 'p', InputOption::VALUE_OPTIONAL, 'Slug do parceiro específico (omita para todos)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Coleta Waze — Multi-Tenant');

        $partnerSlug = $input->getOption('partner');

        $partners = $partnerSlug
            ? array_filter($this->partnerRepository->findActivePartners(), fn ($p) => $p->getSlug() === $partnerSlug)
            : $this->partnerRepository->findActivePartners();

        if (empty($partners)) {
            $io->warning('Nenhum parceiro ativo encontrado.');
            return Command::SUCCESS;
        }

        foreach ($partners as $partner) {
            $this->tenantContext->setPartner($partner);
            $io->section("Parceiro: {$partner->getName()} [{$partner->getSlug()}]");

            try {
                $this->collectForPartner($partner, $io);
            } catch (\Throwable $e) {
                $this->logger->error('Falha na coleta Waze', [
                    'partner' => $partner->getSlug(),
                    'error'   => $e->getMessage(),
                ]);
                $io->error("Erro: {$e->getMessage()}");
            }
        }

        $io->success('Coleta Waze concluída.');
        return Command::SUCCESS;
    }

    private function collectForPartner(Partner $partner, SymfonyStyle $io): void
    {
        $url = $this->buildUrl($partner);
        $io->text("GET {$url}");

        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $data = $response->toArray();

        $alertsSaved = $this->processAlerts($data['alerts'] ?? [], $partner);
        $jamsSaved   = $this->processJams($data['jams'] ?? [], $partner);

        $this->em->flush();

        $io->success("Alertas: {$alertsSaved} | Congestionamentos: {$jamsSaved}");
    }

    private function processAlerts(array $alerts, Partner $partner): int
    {
        $saved = 0;
        foreach ($alerts as $item) {
            $wazeId = (string) ($item['uuid'] ?? $item['id'] ?? '');
            if (!$wazeId) continue;

            $existing = $this->alertRepository->findOneBy(['wazeId' => $wazeId, 'partner' => $partner]);
            if ($existing) continue;

            $location = $item['location'] ?? [];

            $alert = (new WazeAlert())
                ->setPartner($partner)
                ->setWazeId($wazeId)
                ->setType((string) ($item['type'] ?? ''))
                ->setSubtype($item['subtype'] ?? null)
                ->setLatitude((float) ($location['y'] ?? 0))
                ->setLongitude((float) ($location['x'] ?? 0))
                ->setStreet((string) ($item['street'] ?? ''))
                ->setCity((string) ($item['city'] ?? ''))
                ->setCountry((string) ($item['country'] ?? 'BR'))
                ->setReliability((int) ($item['reliability'] ?? 0))
                ->setConfidence((int) ($item['confidence'] ?? 0))
                ->setReportRating((int) ($item['reportRating'] ?? 0))
                ->setPubMillis((int) ($item['pubMillis'] ?? 0));

            $this->em->persist($alert);
            $saved++;
        }
        return $saved;
    }

    private function processJams(array $jams, Partner $partner): int
    {
        $saved = 0;
        foreach ($jams as $item) {
            $wazeId = (string) ($item['uuid'] ?? $item['id'] ?? '');
            if (!$wazeId) continue;

            $existing = $this->jamRepository->findOneBy(['wazeId' => $wazeId, 'partner' => $partner]);
            if ($existing) continue;

            $jam = (new WazeTrafficJam())
                ->setPartner($partner)
                ->setWazeId($wazeId)
                ->setStreet((string) ($item['street'] ?? ''))
                ->setCity((string) ($item['city'] ?? ''))
                ->setLevel((int) ($item['level'] ?? 0))
                ->setSpeedKmh((float) ($item['speedKMH'] ?? 0))
                ->setLength((float) ($item['length'] ?? 0))
                ->setDelay((int) ($item['delay'] ?? 0))
                ->setLine($item['line'] ?? [])
                ->setPubMillis((int) ($item['pubMillis'] ?? 0));

            $this->em->persist($jam);
            $saved++;
        }
        return $saved;
    }

    private function buildUrl(Partner $partner): string
    {
        $url = self::WAZE_URL;
        if ($partner->getBbox()) {
            $url .= '&bbox=' . urlencode($partner->getBbox());
        }
        return $url;
    }
}
