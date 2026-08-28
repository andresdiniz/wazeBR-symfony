<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CemadenData;
use App\Entity\Partner;
use App\Repository\CemadenDataRepository;
use App\Repository\PartnerRepository;
use App\Service\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'cemaden:collect',
    description: 'Coleta chuva horária de estações pluviométricas CEMADEN cadastradas.',
)]
class CemadenCollectCommand extends Command
{
    private const STATION_HOURLY_URL =
        'https://mapservices.cemaden.gov.br/MapaInterativoWS/resources/horario/%d/23';

    private readonly \DateTimeZone $utcTimezone;
    private readonly \DateTimeZone $saoPauloTimezone;

    public function __construct(
        private readonly PartnerRepository $partnerRepo,
        private readonly CemadenDataRepository $cemadenRepo,
        private readonly TenantContext $tenantContext,
        private readonly HttpClientInterface $httpClient,
        private readonly Connection $db,
    ) {
        parent::__construct();

        $this->utcTimezone = new \DateTimeZone('UTC');
        $this->saoPauloTimezone = new \DateTimeZone('America/Sao_Paulo');
    }

    protected function configure(): void
    {
        $this->addOption(
            'partner',
            'p',
            InputOption::VALUE_OPTIONAL,
            'Slug do parceiro; omitir para coletar para todos os parceiros ativos.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('CEMADEN Collect — Chuvas por estação pluviométrica');

        $partnerSlug = $input->getOption('partner');

        $partners = $this->findPartnersToCollect(
            is_string($partnerSlug) && $partnerSlug !== ''
                ? $partnerSlug
                : null,
        );

        if ($partners === []) {
            $io->warning('Nenhum parceiro ativo encontrado.');

            return Command::SUCCESS;
        }

        foreach ($partners as $partner) {
            $this->tenantContext->setPartner($partner);

            $io->section(sprintf(
                'Parceiro: %s [%s]',
                $partner->getName(),
                $partner->getSlug(),
            ));

            $stations = $this->loadPluviometricStations((int) $partner->getId());

            if ($stations === []) {
                $io->warning(
                    'Nenhuma estação pluviométrica ativa cadastrada em cemaden_stations. Pulando.',
                );

                continue;
            }

            $io->text(sprintf(
                'Estações pluviométricas autorizadas: %s',
                implode(
                    ', ',
                    array_map(
                        static fn (array $station): string => sprintf(
                            '%s (%s)',
                            $station['cod_estacao'],
                            $station['cemaden_station_id'],
                        ),
                        $stations,
                    ),
                ),
            ));

            $partnerTotal = 0;

            foreach ($stations as $station) {
                try {
                    $count = $this->collectPluviometricStation($partner, $station);

                    $io->text(sprintf(
                        'Estação %s: %d novo(s) registro(s) de chuva.',
                        $station['cod_estacao'],
                        $count,
                    ));

                    $partnerTotal += $count;
                } catch (\Throwable $exception) {
                    $io->error(sprintf(
                        'Erro na estação %s: %s',
                        $station['cod_estacao'] ?? 'desconhecida',
                        $exception->getMessage(),
                    ));
                }
            }

            $io->success(sprintf(
                'Total [%s]: %d novo(s) registro(s) pluviométricos.',
                $partner->getSlug(),
                $partnerTotal,
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<Partner>
     */
    private function findPartnersToCollect(?string $partnerSlug): array
    {
        $partners = $this->partnerRepo->findActivePartners();

        if ($partnerSlug === null) {
            return is_array($partners) ? $partners : iterator_to_array($partners);
        }

        $filtered = [];

        foreach ($partners as $partner) {
            if ($partner->getSlug() === $partnerSlug) {
                $filtered[] = $partner;
            }
        }

        return $filtered;
    }

    /**
     * `hydro_url` mantém o ID numérico da estação no Mapa Interativo CEMADEN.
     *
     * Exemplo de registro esperado:
     * - partner_id: 2
     * - station_type: pluviometric
     * - cod_estacao: 311830403A
     * - hydro_url: 4142
     *
     * @return list<array{
     *     id: int|string,
     *     cod_estacao: string,
     *     nome: string|null,
     *     municipio: string|null,
     *     uf: string|null,
     *     lat: mixed,
     *     lng: mixed,
     *     cemaden_station_id: int
     * }>
     */
    private function loadPluviometricStations(int $partnerId): array
    {
        $rows = $this->db->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    id,
                    cod_estacao,
                    nome,
                    municipio,
                    uf,
                    lat,
                    lng,
                    hydro_url
                FROM cemaden_stations
                WHERE partner_id = :partnerId
                  AND station_type = 'pluviometric'
                  AND is_active = 1
                  AND hydro_url IS NOT NULL
                  AND hydro_url <> ''
                ORDER BY nome ASC, cod_estacao ASC
            SQL,
            [
                'partnerId' => $partnerId,
            ],
        );

        $stations = [];

        foreach ($rows as $row) {
            $cemadenStationId = $this->extractCemadenStationId(
                $row['hydro_url'] ?? null,
            );

            if ($cemadenStationId === null) {
                continue;
            }

            $stations[] = [
                'id' => $row['id'],
                'cod_estacao' => trim((string) $row['cod_estacao']),
                'nome' => $row['nome'] !== null ? (string) $row['nome'] : null,
                'municipio' => $row['municipio'] !== null ? (string) $row['municipio'] : null,
                'uf' => $row['uf'] !== null ? (string) $row['uf'] : null,
                'lat' => $row['lat'],
                'lng' => $row['lng'],
                'cemaden_station_id' => $cemadenStationId,
            ];
        }

        return $stations;
    }

    /**
     * Aceita:
     * - "4142"
     * - 4142
     * - uma URL eventualmente cadastrada por engano, contendo /4142/23.
     */
    private function extractCemadenStationId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('#/horario/(\d+)(?:/|$)#', $value, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Lê o endpoint:
     * /resources/horario/{idEstacao}/23
     *
     * e persiste cada valor de `acumulados` como um registro de chuva horária.
     *
     * @param array{
     *     id: int|string,
     *     cod_estacao: string,
     *     nome: string|null,
     *     municipio: string|null,
     *     uf: string|null,
     *     lat: mixed,
     *     lng: mixed,
     *     cemaden_station_id: int
     * } $station
     */
    private function collectPluviometricStation(
        Partner $partner,
        array $station,
    ): int {
        $url = sprintf(
            self::STATION_HOURLY_URL,
            $station['cemaden_station_id'],
        );

        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        $payload = $response->toArray(false);

        if (!is_array($payload)) {
            throw new \RuntimeException('Resposta inválida da API CEMADEN.');
        }

        $hours = $payload['horarios'] ?? null;
        $dates = $payload['datas'] ?? null;
        $accumulations = $payload['acumulados'] ?? null;
        $apiStation = $payload['estacao'] ?? [];

        if (
            !is_array($hours)
            || !is_array($dates)
            || !is_array($accumulations)
        ) {
            throw new \RuntimeException(
                'A resposta CEMADEN não contém horarios, datas e acumulados válidos.',
            );
        }

        $stationCode = trim(
            (string) (
                $apiStation['codEstacao']
                ?? $station['cod_estacao']
            ),
        );

        if ($stationCode === '') {
            throw new \RuntimeException('A estação não possui codEstacao válido.');
        }

        $stationName = (string) (
            $apiStation['nome']
            ?? $station['nome']
            ?? ''
        );

        $municipality = (string) (
            $apiStation['idMunicipio']['cidade']
            ?? $station['municipio']
            ?? ''
        );

        $state = (string) (
            $apiStation['idMunicipio']['uf']
            ?? $station['uf']
            ?? ''
        );

        $latitude = $this->toFloatOrNull(
            $apiStation['latitude']
            ?? $station['lat']
            ?? null,
        );

        $longitude = $this->toFloatOrNull(
            $apiStation['longitude']
            ?? $station['lng']
            ?? null,
        );

        $this->updateStationCoordinatesIfMissing(
            (int) $station['id'],
            $station['lat'],
            $station['lng'],
            $latitude,
            $longitude,
        );

        $count = 0;

        foreach ($dates as $dayIndex => $dateValue) {
            $hourlyValues = $accumulations[$dayIndex] ?? null;

            if (!is_array($hourlyValues)) {
                continue;
            }

            foreach ($hours as $hourIndex => $hourLabel) {
                if (!array_key_exists($hourIndex, $hourlyValues)) {
                    continue;
                }

                $accumulatedRain = $this->toFloatOrNull(
                    $hourlyValues[$hourIndex],
                );

                if ($accumulatedRain === null) {
                    continue;
                }

                $measuredAtUtc = $this->createUtcMeasuredAt(
                    (string) $dateValue,
                    $hourLabel,
                );

                if ($measuredAtUtc === null) {
                    continue;
                }

                $existing = $this->cemadenRepo->findOneBy([
                    'partner' => $partner,
                    'stationCode' => $stationCode,
                    'measuredAt' => $measuredAtUtc,
                ]);

                if ($existing !== null) {
                    continue;
                }

                $item = (new CemadenData())
                    ->setPartner($partner)
                    ->setStationCode($stationCode)
                    ->setStationName($stationName)
                    ->setMunicipality($municipality)
                    ->setState($state)
                    ->setLatitude($latitude ?? 0.0)
                    ->setLongitude($longitude ?? 0.0)
                    ->setAccumulatedRain($accumulatedRain)
                    ->setAlertLevel(
                        $this->resolveAlertLevel($accumulatedRain),
                    )
                    ->setMeasuredAt($measuredAtUtc);

                $this->cemadenRepo->save($item, false);

                $count++;
            }
        }

        if ($count > 0) {
            $this->cemadenRepo->getEntityManager()->flush();
        }

        return $count;
    }

    /**
     * Interpreta "28/08/2026" + "19h" como 19:00 no fuso America/Sao_Paulo.
     * O valor retornado fica em UTC, que é o formato recomendado para persistência.
     */
    private function createUtcMeasuredAt(
        string $date,
        mixed $hourLabel,
    ): ?\DateTimeImmutable {
        $hour = $this->parseHour($hourLabel);

        if ($hour === null) {
            return null;
        }

        $date = trim($date);

        $local = \DateTimeImmutable::createFromFormat(
            '!d/m/Y H:i:s',
            sprintf('%s %02d:00:00', $date, $hour),
            $this->saoPauloTimezone,
        );

        if ($local === false) {
            return null;
        }

        return $local->setTimezone($this->utcTimezone);
    }

    private function parseHour(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 && $value <= 23 ? $value : null;
        }

        if (!is_string($value)) {
            return null;
        }

        if (!preg_match('/^(\d{1,2})h$/i', trim($value), $matches)) {
            return null;
        }

        $hour = (int) $matches[1];

        return $hour >= 0 && $hour <= 23 ? $hour : null;
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function updateStationCoordinatesIfMissing(
        int $stationId,
        mixed $storedLatitude,
        mixed $storedLongitude,
        ?float $latitude,
        ?float $longitude,
    ): void {
        if ($latitude === null || $longitude === null) {
            return;
        }

        $hasLatitude = $storedLatitude !== null && $storedLatitude !== '';
        $hasLongitude = $storedLongitude !== null && $storedLongitude !== '';

        if ($hasLatitude && $hasLongitude) {
            return;
        }

        $this->db->update(
            'cemaden_stations',
            [
                'lat' => $latitude,
                'lng' => $longitude,
            ],
            [
                'id' => $stationId,
            ],
        );
    }

    private function resolveAlertLevel(float $rain): string
    {
        return match (true) {
            $rain >= 50.0 => 'VERMELHO',
            $rain >= 30.0 => 'LARANJA',
            $rain >= 15.0 => 'AMARELO',
            $rain > 0.0 => 'VERDE',
            default => 'SEM_CHUVA',
        };
    }
}
