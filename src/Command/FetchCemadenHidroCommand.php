<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CemadenHidroObservation;
use App\Entity\CemadenHidroStationLink;
use App\Entity\Partner;
use App\Repository\CemadenHidroStationLinkRepository;
use App\Service\Tv\TvNotifier;
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
    name: 'app:fetch:cemaden:hidro',
    description: 'Coleta observações hidrológicas CEMADEN (nível do rio + chuva) para stations ativas.',
)]
class FetchCemadenHidroCommand extends Command
{
    private const DEFAULT_MIN_INTERVAL_MINUTES = 55;

    private const TZ_SP  = 'America/Sao_Paulo';
    private const TZ_UTC = 'UTC';

    public function __construct(
        private readonly CemadenHidroStationLinkRepository $stationLinkRepository,
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly TvNotifier $tvNotifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner', null, InputOption::VALUE_REQUIRED, 'ID do partner (processa só esse partner)')
            ->addOption('min-interval', null, InputOption::VALUE_REQUIRED, sprintf('Intervalo mínimo entre coletas em minutos (padrão: %d)', self::DEFAULT_MIN_INTERVAL_MINUTES), self::DEFAULT_MIN_INTERVAL_MINUTES)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora lastFetchedAt e força coleta de todas as stations ativas')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula sem persistir no banco');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->resetStaleConnection();

        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $partnerId = $input->getOption('partner');
        $minIntervalMinutes = (int) $input->getOption('min-interval');

        $io->title('Coleta CEMADEN — Hidrológica (Nível do Rio + Chuva)');

        if ($dryRun) {
            $io->warning('Modo DRY-RUN — nenhum dado será persistido.');
        }

        if ($force) {
            $stations = $this->stationLinkRepository->findAllActive();
            $io->info('--force ativo: coletando todas as stations hidro ativas.');
        } else {
            $threshold = new \DateTimeImmutable(
                sprintf('-%d minutes', $minIntervalMinutes),
                new \DateTimeZone(self::TZ_UTC),
            );
            $stations = $this->stationLinkRepository->findDueForCollection($threshold);
            $io->info(sprintf(
                'Buscando stations com lastFetchedAt anterior a %s (intervalo: %d min).',
                $threshold->format('Y-m-d H:i:s'),
                $minIntervalMinutes,
            ));
        }

        if ($partnerId !== null) {
            $stations = array_values(array_filter(
                $stations,
                static fn ($s) => (string) $s->getPartner()->getId() === (string) $partnerId,
            ));
        }

        if (empty($stations)) {
            $io->info('Nenhuma station hidro elegível para coleta no momento.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Processando %d station(s).', count($stations)));

        $totalLevels = 0;
        $totalRain = 0;
        $errors = 0;

        /** @var array<int, Partner> $touchedPartners */
        $touchedPartners = [];

        foreach ($stations as $station) {
            $label = $this->stationLabel($station);

            try {
                $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ_UTC));

                $levelItems = $this->fetchJson($station->getMedidaRequestUrl());
                $levels = $this->persistLevels($station, $levelItems, $dryRun, $label);
                $totalLevels += $levels;

                $rainItems = $this->fetchJson($station->getRequestUrl());
                $rain = $this->persistRain($station, $rainItems, $dryRun, $label);
                $totalRain += $rain;

                if (!$dryRun) {
                    $station->setLastFetchedAt($nowUtc);
                    $this->em->flush();

                    if ($levels > 0 || $rain > 0) {
                        $partner = $station->getPartner();
                        if ($partner instanceof Partner && $partner->getId() !== null) {
                            $touchedPartners[$partner->getId()] = $partner;
                        }
                    }
                }

                $io->writeln(sprintf(
                    '%s ✓ níveis: %d, chuva: %d',
                    $label,
                    $levels,
                    $rain,
                ));
            } catch (\Throwable $e) {
                ++$errors;
                $this->logger->error('{loc}: erro ao coletar — {msg}', [
                    'loc' => $label,
                    'msg' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $io->error(sprintf('%s %s', $label, $e->getMessage()));
            }
        }

        // ▼ Notifica as TVs dos partners afetados
        if (!$dryRun && $touchedPartners !== []) {
            $this->tvNotifier->notifyMany(array_values($touchedPartners));
        }

        $io->success(sprintf(
            'Concluído — Níveis: %d | Chuva: %d | Erros: %d',
            $totalLevels,
            $totalRain,
            $errors,
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function persistLevels(
        CemadenHidroStationLink $station,
        array $items,
        bool $dryRun,
        string $label,
    ): int {
        $inserted = 0;

        foreach ($items as $item) {
            $observedAt = $this->parseDateTime($item['datahora'] ?? null);
            if ($observedAt === null) {
                $this->logger->warning('{loc}: item de nível sem datahora — ignorado.', [
                    'loc' => $label,
                    'item' => $item,
                ]);
                continue;
            }

            $existing = $this->em
                ->getRepository(CemadenHidroObservation::class)
                ->findOneBy([
                    'cemadenHidroStationLink' => $station,
                    'observedAt' => $observedAt,
                    'observationType' => CemadenHidroObservation::TYPE_LEVEL,
                ]);

            if ($existing !== null) {
                continue;
            }

            $valor = isset($item['valor']) && $item['valor'] !== '' ? (float) $item['valor'] : null;
            $offset = isset($item['offset']) && $item['offset'] !== '' ? (float) $item['offset'] : null;

            $waterLevel = ($valor !== null && $offset !== null)
                ? round($offset - $valor, 3)
                : null;

            $entity = new CemadenHidroObservation();
            $entity->setObservationType(CemadenHidroObservation::TYPE_LEVEL);
            $entity->setPartner($station->getPartner());
            $entity->setCemadenHidroStationLink($station);
            $entity->setStationCode($item['codigo'] ?? null);
            $entity->setStationName($item['estacao'] ?? null);
            $entity->setCity($item['cidade'] ?? null);
            $entity->setState($item['uf'] ?? null);
            $entity->setRawValue($valor);
            $entity->setOffset($offset);
            $entity->setWaterLevel($waterLevel);
            $entity->setCotaAtencao(isset($item['cota_atencao']) && $item['cota_atencao'] !== '' ? (float) $item['cota_atencao'] : null);
            $entity->setCotaAlerta(isset($item['cota_alerta']) && $item['cota_alerta'] !== '' ? (float) $item['cota_alerta'] : null);
            $entity->setCotaTransbordamento(isset($item['cota_transbordamento']) && $item['cota_transbordamento'] !== '' ? (float) $item['cota_transbordamento'] : null);
            $entity->setObservedAt($observedAt);
            $entity->setSourcePayload($item);

            if (!$dryRun) {
                $this->em->persist($entity);
            }
            ++$inserted;
        }

        return $inserted;
    }

    private function persistRain(
        CemadenHidroStationLink $station,
        array $items,
        bool $dryRun,
        string $label,
    ): int {
        $inserted = 0;

        foreach ($items as $item) {
            $observedAt = $this->parseDateTime($item['datahora'] ?? null);
            if ($observedAt === null) {
                continue;
            }

            $existing = $this->em
                ->getRepository(CemadenHidroObservation::class)
                ->findOneBy([
                    'cemadenHidroStationLink' => $station,
                    'observedAt' => $observedAt,
                    'observationType' => CemadenHidroObservation::TYPE_RAIN,
                ]);

            if ($existing !== null) {
                continue;
            }

            $rainValue = isset($item['valor']) && $item['valor'] !== '' ? (float) $item['valor'] : null;

            $entity = new CemadenHidroObservation();
            $entity->setObservationType(CemadenHidroObservation::TYPE_RAIN);
            $entity->setPartner($station->getPartner());
            $entity->setCemadenHidroStationLink($station);
            $entity->setStationCode($item['codigo'] ?? null);
            $entity->setStationName($item['estacao'] ?? null);
            $entity->setCity($item['cidade'] ?? null);
            $entity->setState($item['uf'] ?? null);
            $entity->setRain($rainValue);
            $entity->setObservedAt($observedAt);
            $entity->setSourcePayload($item);

            if (!$dryRun) {
                $this->em->persist($entity);
            }
            ++$inserted;
        }

        return $inserted;
    }

    private function parseDateTime(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $tzSp  = new \DateTimeZone(self::TZ_SP);
        $tzUtc = new \DateTimeZone(self::TZ_UTC);

        $dt = null;

        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value, $tzSp);
            if ($parsed !== false) {
                $dt = $parsed;
                break;
            }
        }

        if ($dt === null) {
            try {
                $dt = new \DateTimeImmutable($value, $tzSp);
            } catch (\Throwable) {
                return null;
            }
        }

        return $dt->setTimezone($tzUtc);
    }

    /** @return list<array<string, mixed>> */
    private function fetchJson(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, ['timeout' => 15]);
        $data = $response->toArray();

        if (isset($data['data']) && is_array($data['data'])) {
            return array_values($data['data']);
        }

        return array_values($data);
    }

    private function stationLabel(CemadenHidroStationLink $station): string
    {
        return sprintf(
            '[Partner %d | HidroStation %d — %s]',
            $station->getPartner()->getId(),
            $station->getId(),
            $station->getStationName() ?? $station->getCemadenTransactionId(),
        );
    }

    private function resetStaleConnection(): void
    {
        $connection = $this->em->getConnection();

        try {
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            $connection->close();
        }
    }
}
