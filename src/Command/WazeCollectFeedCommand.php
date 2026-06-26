<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MonitoredLink;
use App\Entity\Partner;
use App\Entity\WazeAlert;
use App\Entity\WazeTrafficJam;
use App\Repository\MonitoredLinkRepository;
use App\Repository\PartnerRepository;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeTrafficJamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Coleta alertas e congestionamentos do feed Waze PartnerHub.
 *
 * URL do feed (feedFormat=1 em MonitoredLink):
 *   https://www.waze.com/row-partnerhub-api/partners/{partnerId}/waze-feeds/{uuid}?format=1
 */
#[AsCommand(
    name: 'app:waze:collect-feed',
    description: 'Coleta alertas e jams do feed PartnerHub Waze para todos os links ativos.',
)]
class WazeCollectFeedCommand extends Command
{
    public function __construct(
        private readonly PartnerRepository        $partnerRepo,
        private readonly MonitoredLinkRepository  $linkRepo,
        private readonly WazeAlertRepository      $alertRepo,
        private readonly WazeTrafficJamRepository $jamRepo,
        private readonly EntityManagerInterface   $em,
        private readonly HttpClientInterface      $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner', 'p', InputOption::VALUE_OPTIONAL,
                'Slug do parceiro — omitir para processar todos os ativos')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Busca e exibe o JSON sem persistir nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $slug   = $input->getOption('partner');

        $io->title('Waze Collect Feed — PartnerHub');
        if ($dryRun) {
            $io->note('Modo DRY-RUN: nenhum dado será persistido.');
        }

        $partners = $slug
            ? array_filter(
                $this->partnerRepo->findActivePartners(),
                fn (Partner $p) => $p->getSlug() === $slug,
            )
            : $this->partnerRepo->findActivePartners();

        if (empty($partners)) {
            $io->warning('Nenhum parceiro ativo encontrado.');
            return Command::SUCCESS;
        }

        $totalAlerts = 0;
        $totalJams   = 0;

        foreach ($partners as $partner) {
            $io->section("Parceiro: {$partner->getName()} [{$partner->getSlug()}]");

            // feedFormat=1 => feeds Waze PartnerHub (alertas + jams)
            /** @var MonitoredLink[] $links */
            $links = $this->linkRepo->findBy([
                'partner'    => $partner,
                'feedFormat' => MonitoredLinkRepository::FORMAT_WAZE,
                'isActive'   => true,
            ]);

            if (empty($links)) {
                $io->warning('Nenhum link de feed ativo (feedFormat=1). Cadastre um MonitoredLink.');
                continue;
            }

            foreach ($links as $link) {
                $label = $link->getLabel() ?? $link->getUrl();
                $io->writeln("  → [{$label}] {$link->getUrl()}");

                try {
                    $data = $this->fetchFeed($link->getUrl());
                } catch (\Throwable $e) {
                    $io->error('  ✗ Erro ao buscar feed: ' . $e->getMessage());
                    continue;
                }

                if ($dryRun) {
                    $io->writeln(sprintf(
                        '  DRY-RUN: %d alertas, %d jams encontrados.',
                        count($data['alerts'] ?? []),
                        count($data['jams'] ?? []),
                    ));
                    continue;
                }

                $feedStart = isset($data['startTimeMillis']) ? (int) $data['startTimeMillis'] : null;

                $alerts = $this->persistAlerts($partner, $link, $data['alerts'] ?? [], $feedStart);
                $jams   = $this->persistJams($partner, $link, $data['jams'] ?? [], $feedStart);

                $link->setLastCollectedAt(new \DateTimeImmutable());
                $this->em->flush();

                $totalAlerts += $alerts;
                $totalJams   += $jams;

                $io->writeln(sprintf(
                    '  ✓ Alertas: <info>%d novos</info> | Jams: <info>%d novos</info>',
                    $alerts,
                    $jams,
                ));
            }
        }

        if (!$dryRun) {
            $io->success("Total salvo — Alertas: {$totalAlerts} | Jams: {$totalJams}");
        }

        return Command::SUCCESS;
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────

    private function fetchFeed(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 30,
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => 'WazeBR-Symfony/1.0',
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException("HTTP {$status} ao acessar {$url}");
        }

        return $response->toArray();
    }

    // ── Persistência — Alertas ────────────────────────────────────────────────

    private function persistAlerts(
        Partner       $partner,
        MonitoredLink $link,
        array         $rawAlerts,
        ?int          $feedStart,
    ): int {
        $count = 0;

        foreach ($rawAlerts as $raw) {
            $wazeId = (string) ($raw['uuid'] ?? '');
            if ($wazeId === '') {
                continue;
            }

            if ($this->alertRepo->findOneBy(['wazeId' => $wazeId, 'partner' => $partner])) {
                continue;
            }

            $alert = (new WazeAlert())
                ->setPartner($partner)
                ->setSourceLink($link)
                ->setWazeId($wazeId)
                ->setType((string) ($raw['type'] ?? 'UNKNOWN'))
                ->setSubtype((string) ($raw['subtype'] ?? '') ?: null)
                ->setLatitude((float) ($raw['location']['y'] ?? 0.0))
                ->setLongitude((float) ($raw['location']['x'] ?? 0.0))
                ->setStreet(isset($raw['street'])       ? mb_substr((string) $raw['street'],  0, 120) : null)
                ->setCity(isset($raw['city'])           ? mb_substr((string) $raw['city'],    0, 80)  : null)
                ->setCountry(isset($raw['country'])     ? mb_substr((string) $raw['country'], 0, 10)  : null)
                ->setRoadType(isset($raw['roadType'])   ? (int) $raw['roadType']   : null)
                ->setReliability(isset($raw['reliability']) ? (int) $raw['reliability'] : null)
                ->setConfidence(isset($raw['confidence'])   ? (int) $raw['confidence']  : null)
                ->setReportRating(isset($raw['reportRating']) ? (int) $raw['reportRating'] : null)
                ->setNThumbsUp(isset($raw['nThumbsUp']) ? (int) $raw['nThumbsUp'] : null)
                ->setMagvar(isset($raw['magvar'])       ? (int) $raw['magvar']     : null)
                ->setPubMillis((int) ($raw['pubMillis'] ?? 0))
                ->setFeedStartMillis($feedStart);

            $this->em->persist($alert);
            $count++;
        }

        if ($count > 0) {
            $this->em->flush();
        }

        return $count;
    }

    // ── Persistência — Jams ───────────────────────────────────────────────────

    private function persistJams(
        Partner       $partner,
        MonitoredLink $link,
        array         $rawJams,
        ?int          $feedStart,
    ): int {
        $count = 0;

        foreach ($rawJams as $raw) {
            $wazeId = (string) ($raw['uuid'] ?? $raw['id'] ?? '');
            if ($wazeId === '') {
                continue;
            }

            if ($this->jamRepo->findOneBy(['wazeId' => $wazeId, 'partner' => $partner])) {
                continue;
            }

            $jam = (new WazeTrafficJam())
                ->setPartner($partner)
                ->setSourceLink($link)
                ->setWazeId($wazeId)
                ->setStreet(isset($raw['street'])   ? mb_substr((string) $raw['street'],  0, 120) : null)
                ->setCity(isset($raw['city'])       ? mb_substr((string) $raw['city'],    0, 80)  : null)
                ->setCountry(isset($raw['country']) ? mb_substr((string) $raw['country'], 0, 10)  : null)
                ->setRoadType(isset($raw['roadType']) ? (int) $raw['roadType'] : null)
                ->setLevel(isset($raw['level'])     ? (int)   $raw['level']   : null)
                ->setSpeedKmh(isset($raw['speedKMH']) ? (float) $raw['speedKMH'] : null)
                ->setLength(isset($raw['length'])   ? (float) $raw['length']   : null)
                ->setDelay(isset($raw['delay'])     ? (int)   $raw['delay']   : null)
                ->setTurnType(isset($raw['turnType']) ? mb_substr((string) $raw['turnType'], 0, 40)  : null)
                ->setEndNode(isset($raw['endNode'])   ? mb_substr((string) $raw['endNode'],  0, 200) : null)
                ->setLine($raw['line'] ?? [])
                ->setSegments($raw['segments'] ?? [])
                ->setCausedBy(isset($raw['blockingAlertUuid']) ? (string) $raw['blockingAlertUuid'] : null)
                ->setPubMillis((int) ($raw['pubMillis'] ?? 0))
                ->setFeedStartMillis($feedStart);

            $this->em->persist($jam);
            $count++;
        }

        if ($count > 0) {
            $this->em->flush();
        }

        return $count;
    }
}
