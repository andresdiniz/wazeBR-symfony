<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\WeatherObservation;
use App\Repository\WeatherLocationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Coleta observações meteorológicas para todas as WeatherLocations ativas.
 *
 * Uso:
 *   php bin/console app:fetch:weather
 *   php bin/console app:fetch:weather --partner=42
 *   php bin/console app:fetch:weather --provider=open-meteo
 *   php bin/console app:fetch:weather --dry-run
 *
 * Agendamento sugerido (Symfony Scheduler ou cron): a cada 15-30 minutos.
 * A idempotência é garantida pelo unique constraint (weather_location_id, observed_at)
 * na entidade WeatherObservation — inserções duplicadas são descartadas silenciosamente.
 */
#[AsCommand(
    name: 'app:fetch:weather',
    description: 'Coleta observações meteorológicas para todas as WeatherLocations ativas.',
)]
class FetchWeatherObservationsCommand extends Command
{
    /** Parâmetros padrão da API Open-Meteo para a rota /v1/forecast (current weather). */
    private const OPEN_METEO_CURRENT_PARAMS = [
        'current' => implode(',', [
            'temperature_2m',
            'apparent_temperature',
            'relative_humidity_2m',
            'precipitation',
            'rain',
            'showers',
            'snowfall',
            'weather_code',
            'cloud_cover',
            'surface_pressure',
            'wind_speed_10m',
            'wind_direction_10m',
            'wind_gusts_10m',
            'visibility',
            'is_day',
        ]),
    ];

    public function __construct(
        private readonly WeatherLocationRepository $locationRepository,
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner', null, InputOption::VALUE_REQUIRED, 'ID do partner (processa só esse partner)')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Filtra por provider (ex: open-meteo)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula sem persistir no banco');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $partnerId = $input->getOption('partner');
        $provider = $input->getOption('provider');

        $io->title('Coleta de Observações Meteorológicas');

        if ($dryRun) {
            $io->warning('Modo DRY-RUN — nenhum dado será persistido.');
        }

        // ── 1. Selecionar locations ──────────────────────────────────────────
        $locations = $provider
            ? $this->locationRepository->findActiveByProvider((string) $provider)
            : $this->locationRepository->findAllActive();

        if ($partnerId !== null) {
            $locations = array_filter(
                $locations,
                static fn ($loc) => (string) $loc->getPartner()->getId() === (string) $partnerId,
            );
            $locations = array_values($locations);
        }

        if (empty($locations)) {
            $io->info('Nenhuma WeatherLocation ativa encontrada para os filtros informados.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Processando %d location(s).', count($locations)));

        // ── 2. Iterar e coletar ──────────────────────────────────────────────
        $inserted = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($locations as $location) {
            $partnerLabel = sprintf(
                '[Partner %d | Location %d — %s]',
                $location->getPartner()->getId(),
                $location->getId(),
                $location->getName(),
            );

            try {
                $payload = $this->fetchOpenMeteo($location->getLatitude(), $location->getLongitude());

                if (!isset($payload['current'])) {
                    $this->logger->warning('{loc}: resposta sem campo "current".', ['loc' => $partnerLabel]);
                    ++$skipped;
                    continue;
                }

                $current = $payload['current'];
                $observedAt = new \DateTimeImmutable($current['time'] ?? 'now');

                // Idempotência: verifica se já existe observação para este instante
                $existing = $this->em->getRepository(WeatherObservation::class)->findOneBy([
                    'weatherLocation' => $location,
                    'observedAt' => $observedAt,
                ]);

                if ($existing !== null) {
                    $io->writeln(sprintf('%s já coletado para %s — ignorando.', $partnerLabel, $observedAt->format('Y-m-d H:i')));
                    ++$skipped;
                    continue;
                }

                $observation = new WeatherObservation();
                $observation->setPartner($location->getPartner());
                $observation->setWeatherLocation($location);
                $observation->setObservedAt($observedAt);
                $observation->setTemperature($this->floatOrNull($current, 'temperature_2m'));
                $observation->setApparentTemperature($this->floatOrNull($current, 'apparent_temperature'));
                $observation->setRelativeHumidity($this->intOrNull($current, 'relative_humidity_2m'));
                $observation->setPrecipitation($this->floatOrNull($current, 'precipitation'));
                $observation->setRain($this->floatOrNull($current, 'rain'));
                $observation->setShowers($this->floatOrNull($current, 'showers'));
                $observation->setSnowfall($this->floatOrNull($current, 'snowfall'));
                $observation->setWeatherCode($this->intOrNull($current, 'weather_code'));
                $observation->setCloudCover($this->intOrNull($current, 'cloud_cover'));
                $observation->setSurfacePressure($this->floatOrNull($current, 'surface_pressure'));
                $observation->setWindSpeed($this->floatOrNull($current, 'wind_speed_10m'));
                $observation->setWindDirection($this->intOrNull($current, 'wind_direction_10m'));
                $observation->setWindGusts($this->floatOrNull($current, 'wind_gusts_10m'));
                $observation->setVisibility($this->floatOrNull($current, 'visibility'));
                $observation->setIsDay((bool) ($current['is_day'] ?? false));
                $observation->setPayload($payload);

                if (!$dryRun) {
                    $this->em->persist($observation);
                    $this->em->flush();
                }

                ++$inserted;
                $io->writeln(sprintf('%s ✓ observação em %s persistida.', $partnerLabel, $observedAt->format('Y-m-d H:i')));
            } catch (\Throwable $e) {
                ++$errors;
                $this->logger->error('{loc}: erro ao coletar — {msg}', [
                    'loc' => $partnerLabel,
                    'msg' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $io->error(sprintf('%s %s', $partnerLabel, $e->getMessage()));
            }
        }

        // ── 3. Sumário ───────────────────────────────────────────────────────
        $io->success(sprintf(
            'Concluído — Inseridos: %d | Ignorados: %d | Erros: %d',
            $inserted,
            $skipped,
            $errors,
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Chama o endpoint Open-Meteo de current weather para as coordenadas informadas.
     *
     * @return array<string, mixed>
     */
    private function fetchOpenMeteo(float $lat, float $lng): array
    {
        $response = $this->httpClient->request(
            'GET',
            'https://api.open-meteo.com/v1/forecast',
            [
                'query' => array_merge(
                    self::OPEN_METEO_CURRENT_PARAMS,
                    ['latitude' => $lat, 'longitude' => $lng],
                ),
                'timeout' => 10,
            ],
        );

        return $response->toArray();
    }

    private function floatOrNull(array $data, string $key): ?float
    {
        return isset($data[$key]) ? (float) $data[$key] : null;
    }

    private function intOrNull(array $data, string $key): ?int
    {
        return isset($data[$key]) ? (int) $data[$key] : null;
    }
}
