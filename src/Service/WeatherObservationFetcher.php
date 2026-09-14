<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\WeatherLocation;
use App\Entity\WeatherObservation;
use App\Repository\WeatherLocationRepository;
use App\Repository\WeatherObservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Busca observações climáticas atuais de cada WeatherLocation ativa.
 *
 * Providers suportados:
 *   - open-meteo (default, sem API key)
 *
 * Adicionar outro provider: crie um método fetchFromX() e um case no match().
 */
final class WeatherObservationFetcher
{
    private const OPEN_METEO_URL = 'https://api.open-meteo.com/v1/forecast';

    /** Campos do "current" que pedimos ao Open-Meteo. */
    private const OPEN_METEO_CURRENT_FIELDS = [
        'temperature_2m',
        'relative_humidity_2m',
        'apparent_temperature',
        'is_day',
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
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly WeatherLocationRepository $locationRepository,
        private readonly WeatherObservationRepository $observationRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Busca e persiste observações para todas as locations ativas.
     *
     * @return array{total:int,inserted:int,skipped:int,failed:int}
     */
    public function fetchAndStoreObservations(): array
    {
        $locations = $this->locationRepository->findAllActive();

        $total = count($locations);
        $inserted = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($locations as $location) {
            try {
                $result = $this->fetchForLocation($location);

                if ($result === null) {
                    $skipped++;
                    continue;
                }

                if ($result === true) {
                    $inserted++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $failed++;

                $this->logger->error(
                    '[weather] Falha em {location}: {msg}',
                    [
                        'location' => $location->getName(),
                        'msg'      => $e->getMessage(),
                        'exception'=> $e,
                    ],
                );
            }
        }

        $this->em->flush();

        return [
            'total'    => $total,
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'failed'   => $failed,
        ];
    }

    /**
     * Busca uma location específica.
     *
     * @return bool|null true=inseriu, false=já existia, null=pulou
     */
    public function fetchForLocation(WeatherLocation $location): ?bool
    {
        $provider = strtolower(trim($location->getProvider()));

        $data = match ($provider) {
            'open-meteo' => $this->fetchFromOpenMeteo($location),
            default      => throw new \RuntimeException(sprintf(
                'Provider de clima não suportado: "%s".',
                $provider,
            )),
        };

        if ($data === null) {
            return null;
        }

        return $this->persist($location, $data);
    }

    /**
     * Chama o Open-Meteo com os campos que temos na entidade.
     *
     * @return array{observedAt:\DateTimeImmutable,payload:array<string,mixed>}|null
     */
    private function fetchFromOpenMeteo(WeatherLocation $location): ?array
    {
        $lat = $location->getLatitude();
        $lng = $location->getLongitude();

        if ($lat === null || $lng === null) {
            $this->logger->warning(
                '[weather] Location "{name}" sem coordenadas — ignorada.',
                ['name' => $location->getName()],
            );

            return null;
        }

        $response = $this->httpClient->request('GET', self::OPEN_METEO_URL, [
            'timeout' => 15,
            'query'   => [
                'latitude'  => $lat,
                'longitude' => $lng,
                'current'   => implode(',', self::OPEN_METEO_CURRENT_FIELDS),
                'timezone'  => 'UTC',
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf(
                'Open-Meteo retornou HTTP %d para "%s".',
                $response->getStatusCode(),
                $location->getName(),
            ));
        }

        $json = $response->toArray(false);
        $current = $json['current'] ?? null;

        if (!is_array($current) || empty($current['time'])) {
            $this->logger->warning(
                '[weather] Resposta do Open-Meteo sem "current" para "{name}".',
                ['name' => $location->getName()],
            );

            return null;
        }

        // "2026-09-13T22:30" → DateTimeImmutable em UTC
        $observedAt = new \DateTimeImmutable(
            (string) $current['time'],
            new \DateTimeZone('UTC'),
        );

        return [
            'observedAt' => $observedAt,
            'payload'    => $current,
        ];
    }

    /**
     * @param array{observedAt:\DateTimeImmutable,payload:array<string,mixed>} $data
     *
     * @return bool true=inseriu, false=já existia
     */
    private function persist(WeatherLocation $location, array $data): bool
    {
        $observedAt = $data['observedAt'];
        $current = $data['payload'];

        $existing = $this->observationRepository
            ->findOneByLocationAndObservedAt($location, $observedAt);

        if ($existing !== null) {
            return false;
        }

        $obs = new WeatherObservation();
        $obs->setPartner($location->getPartner());
        $obs->setWeatherLocation($location);
        $obs->setObservedAt($observedAt);
        $obs->setPayload($current);

        $obs->setTemperature($this->dec($current['temperature_2m'] ?? null));
        $obs->setApparentTemperature($this->dec($current['apparent_temperature'] ?? null));
        $obs->setRelativeHumidity($this->int($current['relative_humidity_2m'] ?? null));
        $obs->setPrecipitation($this->dec($current['precipitation'] ?? null));
        $obs->setRain($this->dec($current['rain'] ?? null));
        $obs->setShowers($this->dec($current['showers'] ?? null));
        $obs->setSnowfall($this->dec($current['snowfall'] ?? null));
        $obs->setWeatherCode($this->int($current['weather_code'] ?? null));
        $obs->setCloudCover($this->int($current['cloud_cover'] ?? null));
        $obs->setSurfacePressure($this->dec($current['surface_pressure'] ?? null));
        $obs->setWindSpeed($this->dec($current['wind_speed_10m'] ?? null));
        $obs->setWindDirection($this->int($current['wind_direction_10m'] ?? null));
        $obs->setWindGusts($this->dec($current['wind_gusts_10m'] ?? null));

        if (isset($current['is_day'])) {
            $obs->setIsDay((bool) $current['is_day']);
        }

        $this->em->persist($obs);

        return true;
    }

    private function dec(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return number_format((float) $v, 2, '.', '');
    }

    private function int(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (int) $v;
    }
}
