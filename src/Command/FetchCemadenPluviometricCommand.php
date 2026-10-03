<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CemadenPluviometricObservation;
use App\Entity\CemadenStationLink;
use App\Entity\Partner;
use App\Repository\CemadenStationLinkRepository;
use App\Service\Tv\TvNotifier;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
    name: 'app:fetch:cemaden:pluviometric',
    description: 'Coleta observações pluviométricas CEMADEN para estações ativas.',
)]
class FetchCemadenPluviometricCommand extends Command
{
    private const DEFAULT_MIN_INTERVAL_MINUTES = 10;
    private const TZ_SP = 'America/Sao_Paulo';
    private const TZ_UTC = 'UTC';

    public function __construct(
        private readonly CemadenStationLinkRepository $stationLinkRepository,
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
            ->addOption(
                'partner',
                null,
                InputOption::VALUE_REQUIRED,
                'ID do partner. Processa somente esse partner.',
            )
            ->addOption(
                'min-interval',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf(
                    'Intervalo mínimo entre coletas em minutos. Padrão: %d.',
                    self::DEFAULT_MIN_INTERVAL_MINUTES,
                ),
                self::DEFAULT_MIN_INTERVAL_MINUTES,
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Ignora lastFetchedAt e força a coleta de todas as estações ativas.',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simula a coleta sem persistir no banco.',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $partnerId = $input->getOption('partner');
        $minIntervalMinutes = max(
            1,
            (int) $input->getOption('min-interval'),
        );

        $io->title('Coleta CEMADEN — Pluviométrica (mm de chuva)');

        if ($dryRun) {
            $io->warning(
                'Modo DRY-RUN ativo. Nenhum dado será persistido.',
            );
        }

        $stations = $this->loadStations(
            $force,
            $minIntervalMinutes,
            $partnerId,
            $io,
        );

        if ($stations === []) {
            $io->info(
                'Nenhuma station elegível para coleta no momento.',
            );

            return Command::SUCCESS;
        }

        $io->info(sprintf(
            'Processando %d station(s).',
            count($stations),
        ));

        $totalInserted = 0;
        $totalUpdated = 0;
        $totalSkipped = 0;
        $errors = 0;

        /** @var array<int, Partner> $touchedPartners */
        $touchedPartners = [];

        foreach ($stations as $station) {
            $result = $this->processStation($station, $dryRun, $io);

            $totalInserted += $result['inserted'];
            $totalUpdated += $result['updated'];
            $totalSkipped += $result['skipped'];

            if ($result['error']) {
                ++$errors;
            }

            if (
                !$dryRun
                && $result['inserted'] + $result['updated'] > 0
            ) {
                $partner = $station->getPartner();

                if (
                    $partner instanceof Partner
                    && $partner->getId() !== null
                ) {
                    $touchedPartners[$partner->getId()] = $partner;
                }
            }
        }

        if (!$dryRun && $touchedPartners !== []) {
            try {
                $this->tvNotifier->notifyMany(
                    array_values($touchedPartners),
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    '[TvNotifier] publish falhou: {message}',
                    [
                        'message' => $e->getMessage(),
                        'exception' => $e,
                    ],
                );

                $io->warning(
                    'Dados salvos, mas a atualização das TVs falhou.',
                );
            }
        }

        $io->success(sprintf(
            'Concluído — Inseridos: %d | Atualizados: %d | Ignorados: %d | Erros: %d',
            $totalInserted,
            $totalUpdated,
            $totalSkipped,
            $errors,
        ));

        return $errors > 0
            ? Command::FAILURE
            : Command::SUCCESS;
    }

    /**
     * @return list<CemadenStationLink>
     */
    private function loadStations(
        bool $force,
        int $minIntervalMinutes,
        mixed $partnerId,
        SymfonyStyle $io,
    ): array {
        if ($force) {
            $stations = $this->stationLinkRepository->findAllActive();

            $io->info(
                '--force ativo: coletando todas as stations ativas.',
            );
        } else {
            $threshold = new \DateTimeImmutable(
                sprintf('-%d minutes', $minIntervalMinutes),
                new \DateTimeZone(self::TZ_UTC),
            );

            $stations = $this->stationLinkRepository
                ->findDueForCollection($threshold);

            $io->info(sprintf(
                'Buscando stations com lastFetchedAt anterior a %s.',
                $threshold->format('Y-m-d H:i:s'),
            ));
        }

        if ($partnerId === null) {
            return array_values($stations);
        }

        return array_values(array_filter(
            $stations,
            static function (CemadenStationLink $station) use ($partnerId): bool {
                return (string) $station->getPartner()->getId()
                    === (string) $partnerId;
            },
        ));
    }

    /**
     * @return array{
     *     inserted: int,
     *     updated: int,
     *     skipped: int,
     *     error: bool
     * }
     */
    private function processStation(
        CemadenStationLink $station,
        bool $dryRun,
        SymfonyStyle $io,
    ): array {
        $label = $this->stationLabel($station);

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        try {
            $rawData = $this->fetchJson($station->getRequestUrl());

            if ($rawData === []) {
                $io->writeln(sprintf(
                    '%s payload vazio — nada a persistir.',
                    $label,
                ));

                return [
                    'inserted' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'error' => false,
                ];
            }

            if (!$dryRun) {
                $this->syncStationMetadata($station, $rawData);
            }

            $observations = $this->normalizePayload($rawData);

            if ($observations === []) {
                $io->writeln(sprintf(
                    '%s sem observações válidas.',
                    $label,
                ));

                return [
                    'inserted' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'error' => false,
                ];
            }

            foreach ($observations as $observation) {
                $result = $this->saveObservation(
                    $station,
                    $observation,
                    $rawData,
                    $dryRun,
                );

                $inserted += $result['inserted'];
                $updated += $result['updated'];
                $skipped += $result['skipped'];
            }

            if (!$dryRun) {
                $station->setLastFetchedAt(
                    new \DateTimeImmutable(
                        'now',
                        new \DateTimeZone(self::TZ_UTC),
                    ),
                );

                $this->em->flush();
                $this->em->clear();
            }

            $io->writeln(sprintf(
                '%s ✓ inseridos: %d, atualizados: %d, ignorados: %d',
                $label,
                $inserted,
                $updated,
                $skipped,
            ));

            return [
                'inserted' => $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'error' => false,
            ];
        } catch (\Throwable $e) {
            $this->clearEntityManagerAfterFailure();

            $this->logger->error(
                '{loc}: erro ao coletar — {msg}',
                [
                    'loc' => $label,
                    'msg' => $e->getMessage(),
                    'exception' => $e,
                ],
            );

            $io->error(sprintf(
                '%s %s',
                $label,
                $e->getMessage(),
            ));

            return [
                'inserted' => $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'error' => true,
            ];
        }
    }

    /**
     * @param array{
     *     observedAt: \DateTimeImmutable,
     *     referenceDate: \DateTimeImmutable,
     *     hourSlot: int,
     *     accumulated: ?float
     * } $observation
     *
     * @param array<string, mixed> $rawData
     *
     * @return array{
     *     inserted: int,
     *     updated: int,
     *     skipped: int
     * }
     */
    private function saveObservation(
        CemadenStationLink $station,
        array $observation,
        array $rawData,
        bool $dryRun,
    ): array {
        $repository = $this->em->getRepository(
            CemadenPluviometricObservation::class,
        );

        $existing = $repository->findOneBy([
            'cemadenStationLink' => $station,
            'observedAt' => $observation['observedAt'],
        ]);

        if ($existing instanceof CemadenPluviometricObservation) {
            if (
                !$dryRun
                && $existing->getAccumulatedRainfall() === null
                && $observation['accumulated'] !== null
            ) {
                $existing->setAccumulatedRainfall(
                    $observation['accumulated'],
                );
                $existing->setSourcePayload($rawData);

                return [
                    'inserted' => 0,
                    'updated' => 1,
                    'skipped' => 0,
                ];
            }

            return [
                'inserted' => 0,
                'updated' => 0,
                'skipped' => 1,
            ];
        }

        $entity = new CemadenPluviometricObservation();

        $entity->setPartner($station->getPartner());
        $entity->setCemadenStationLink($station);
        $entity->setReferenceDate($observation['referenceDate']);
        $entity->setHourSlot($observation['hourSlot']);
        $entity->setAccumulatedRainfall(
            $observation['accumulated'],
        );
        $entity->setObservedAt($observation['observedAt']);
        $entity->setSourcePayload($rawData);

        if (!$dryRun) {
            $this->em->persist($entity);
        }

        return [
            'inserted' => 1,
            'updated' => 0,
            'skipped' => 0,
        ];
    }

    private function syncStationMetadata(
        CemadenStationLink $station,
        array $rawData,
    ): void {
        $estacao = $rawData['estacao'] ?? null;

        if (!is_array($estacao)) {
            return;
        }

        $changed = false;

        if (
            $station->getStationName() === null
            && !empty($estacao['nome'])
        ) {
            $station->setStationName((string) $estacao['nome']);
            $changed = true;
        }

        if (
            $station->getStationCode() === null
            && !empty($estacao['codEstacao'])
        ) {
            $station->setStationCode(
                (string) $estacao['codEstacao'],
            );
            $changed = true;
        }

        if (
            $station->getLatitude() === null
            && isset($estacao['latitude'])
        ) {
            $station->setLatitude((string) $estacao['latitude']);
            $changed = true;
        }

        if (
            $station->getLongitude() === null
            && isset($estacao['longitude'])
        ) {
            $station->setLongitude((string) $estacao['longitude']);
            $changed = true;
        }

        if (
            $station->getStatus() === null
            && !empty($estacao['status'])
        ) {
            $station->setStatus((string) $estacao['status']);
            $changed = true;
        }

        $municipio = $estacao['idMunicipio'] ?? null;

        if (is_array($municipio)) {
            if (
                $station->getCity() === null
                && !empty($municipio['cidade'])
            ) {
                $station->setCity((string) $municipio['cidade']);
                $changed = true;
            }

            if (
                $station->getState() === null
                && !empty($municipio['uf'])
            ) {
                $station->setState((string) $municipio['uf']);
                $changed = true;
            }

            if (
                $station->getMunicipalityId() === null
                && isset($municipio['idMunicipio'])
            ) {
                $station->setMunicipalityId(
                    (int) $municipio['idMunicipio'],
                );
                $changed = true;
            }

            if (
                $station->getIbgeCode() === null
                && isset($municipio['codibge'])
            ) {
                $station->setIbgeCode((string) $municipio['codibge']);
                $changed = true;
            }
        }

        $rede = $estacao['idRede'] ?? null;

        if (is_array($rede)) {
            if (
                $station->getNetworkId() === null
                && isset($rede['idRede'])
            ) {
                $station->setNetworkId((int) $rede['idRede']);
                $changed = true;
            }

            if (
                $station->getNetworkName() === null
                && !empty($rede['nome'])
            ) {
                $station->setNetworkName((string) $rede['nome']);
                $changed = true;
            }

            if (
                $station->getNetworkAcronym() === null
                && !empty($rede['sigla'])
            ) {
                $station->setNetworkAcronym((string) $rede['sigla']);
                $changed = true;
            }
        }

        $tipo = $estacao['idTipoestacao'] ?? null;

        if (
            is_array($tipo)
            && $station->getStationType() === null
            && !empty($tipo['descricao'])
        ) {
            $station->setStationType((string) $tipo['descricao']);
            $changed = true;
        }

        if ($changed) {
            $station->setUpdatedAt(
                new \DateTimeImmutable(
                    'now',
                    new \DateTimeZone(self::TZ_UTC),
                ),
            );
        }
    }

    /**
     * O CEMADEN pode enviar:
     *
     * - uma data com poucas horas;
     * - duas datas;
     * - 24 horas;
     * - 124 leituras;
     * - horários correspondentes por índice.
     *
     * @param array<string, mixed> $rawData
     *
     * @return list<array{
     *     observedAt: \DateTimeImmutable,
     *     referenceDate: \DateTimeImmutable,
     *     hourSlot: int,
     *     accumulated: ?float
     * }>
     */
    private function normalizePayload(array $rawData): array
    {
        $datas = $rawData['datas'] ?? [];
        $acumulados = $rawData['acumulados'] ?? [];
        $horarios = $rawData['horarios'] ?? [];

        if (!is_array($datas) || $datas === []) {
            return [];
        }

        if (!is_array($acumulados)) {
            return [];
        }

        if (!is_array($horarios)) {
            $horarios = [];
        }

        $results = [];

        foreach ($datas as $dayIndex => $rawDate) {
            $referenceDateSp = $this->parseDateSp((string) $rawDate);

            if ($referenceDateSp === null) {
                continue;
            }

            $dayValues = $acumulados[$dayIndex] ?? [];

            if (!is_array($dayValues)) {
                continue;
            }

            foreach ($dayValues as $index => $rawValue) {
                $hour = $this->extractHour(
                    $horarios[$index] ?? null,
                );

                if ($hour === null) {
                    continue;
                }

                $observedAtSp = $referenceDateSp->setTime($hour, 0);

                $observedAtUtc = $observedAtSp->setTimezone(
                    new \DateTimeZone(self::TZ_UTC),
                );

                $accumulated = null;

                if (
                    $rawValue !== null
                    && $rawValue !== ''
                    && is_numeric($rawValue)
                ) {
                    $accumulated = (float) $rawValue;
                }

                $results[] = [
                    'observedAt' => $observedAtUtc,
                    'referenceDate' => $referenceDateSp->setTime(0, 0),
                    'hourSlot' => $hour,
                    'accumulated' => $accumulated,
                ];
            }
        }

        return $this->removeDuplicateObservations($results);
    }

    private function extractHour(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            $hour = (int) $value;
        } elseif (is_string($value)) {
            $value = trim($value);

            if (!preg_match('/^(\d{1,2})h?$/i', $value, $matches)) {
                return null;
            }

            $hour = (int) $matches[1];
        } else {
            return null;
        }

        if ($hour < 0 || $hour > 23) {
            return null;
        }

        return $hour;
    }

    /**
     * @param list<array{
     *     observedAt: \DateTimeImmutable,
     *     referenceDate: \DateTimeImmutable,
     *     hourSlot: int,
     *     accumulated: ?float
     * }> $observations
     *
     * @return list<array{
     *     observedAt: \DateTimeImmutable,
     *     referenceDate: \DateTimeImmutable,
     *     hourSlot: int,
     *     accumulated: ?float
     * }>
     */
    private function removeDuplicateObservations(
        array $observations,
    ): array {
        $unique = [];

        foreach ($observations as $observation) {
            $key = $observation['observedAt']->format(
                'Y-m-d H:i:s',
            );

            $unique[$key] = $observation;
        }

        return array_values($unique);
    }

    private function parseDateSp(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $timezone = new \DateTimeZone(self::TZ_SP);

        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat(
                $format,
                $raw,
                $timezone,
            );

            if ($date !== false) {
                return $date->setTime(0, 0);
            }
        }

        try {
            return (new \DateTimeImmutable($raw, $timezone))
                ->setTime(0, 0);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJson(string $url): array
    {
        $response = $this->httpClient->request(
            'GET',
            $url,
            [
                'timeout' => 30,
                'max_duration' => 45,
            ],
        );

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf(
                'CEMADEN respondeu HTTP %d para %s.',
                $response->getStatusCode(),
                $url,
            ));
        }

        $data = $response->toArray(false);

        return is_array($data) ? $data : [];
    }

    private function stationLabel(
        CemadenStationLink $station,
    ): string {
        return sprintf(
            '[Partner %d | Station %d — %s]',
            $station->getPartner()->getId(),
            $station->getId(),
            $station->getStationName()
                ?? $station->getCemadenStationId(),
        );
    }

    private function clearEntityManagerAfterFailure(): void
    {
        if ($this->em->isOpen()) {
            return;
        }

        $this->logger->warning(
            'EntityManager fechado após falha de persistência.',
        );
    }
}
