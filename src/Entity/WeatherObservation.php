<?php

namespace App\Entity;

use App\Repository\WeatherObservationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: WeatherObservationRepository::class)]
#[ORM\Table(name: 'weather_observation')]
#[ORM\UniqueConstraint(name: 'uniq_weather_observation_location_observed_at', columns: ['weather_location_id', 'observed_at'])]
#[ORM\Index(columns: ['partner_id', 'observed_at'])]
#[ORM\Index(columns: ['weather_location_id', 'observed_at'])]
class WeatherObservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Partner $partner = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?WeatherLocation $weatherLocation = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $temperature = null;

    #[ORM\Column(name: 'apparent_temperature', type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $apparentTemperature = null;

    #[ORM\Column(name: 'relative_humidity', type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 0, max: 100)]
    private ?int $relativeHumidity = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $precipitation = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $rain = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $showers = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $snowfall = null;

    #[ORM\Column(name: 'weather_code', type: Types::SMALLINT, nullable: true)]
    private ?int $weatherCode = null;

    #[ORM\Column(name: 'cloud_cover', type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 0, max: 100)]
    private ?int $cloudCover = null;

    #[ORM\Column(name: 'surface_pressure', type: Types::DECIMAL, precision: 7, scale: 2, nullable: true)]
    #[Assert\Positive]
    private ?string $surfacePressure = null;

    #[ORM\Column(name: 'wind_speed', type: Types::DECIMAL, precision: 6, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $windSpeed = null;

    #[ORM\Column(name: 'wind_direction', type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 0, max: 360)]
    private ?int $windDirection = null;

    #[ORM\Column(name: 'wind_gusts', type: Types::DECIMAL, precision: 6, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $windGusts = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $visibility = null;

    #[ORM\Column(name: 'is_day', nullable: true)]
    private ?bool $isDay = null;

    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    private \DateTimeImmutable $observedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->observedAt = $now;
        $this->createdAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): static { $this->partner = $partner; return $this; }
    public function getWeatherLocation(): ?WeatherLocation { return $this->weatherLocation; }
    public function setWeatherLocation(?WeatherLocation $weatherLocation): static { $this->weatherLocation = $weatherLocation; return $this; }
    public function getTemperature(): ?string { return $this->temperature; }
    public function setTemperature(?string $temperature): static { $this->temperature = $temperature; return $this; }
    public function getApparentTemperature(): ?string { return $this->apparentTemperature; }
    public function setApparentTemperature(?string $apparentTemperature): static { $this->apparentTemperature = $apparentTemperature; return $this; }
    public function getRelativeHumidity(): ?int { return $this->relativeHumidity; }
    public function setRelativeHumidity(?int $relativeHumidity): static { $this->relativeHumidity = $relativeHumidity; return $this; }
    public function getPrecipitation(): ?string { return $this->precipitation; }
    public function setPrecipitation(?string $precipitation): static { $this->precipitation = $precipitation; return $this; }
    public function getRain(): ?string { return $this->rain; }
    public function setRain(?string $rain): static { $this->rain = $rain; return $this; }
    public function getShowers(): ?string { return $this->showers; }
    public function setShowers(?string $showers): static { $this->showers = $showers; return $this; }
    public function getSnowfall(): ?string { return $this->snowfall; }
    public function setSnowfall(?string $snowfall): static { $this->snowfall = $snowfall; return $this; }
    public function getWeatherCode(): ?int { return $this->weatherCode; }
    public function setWeatherCode(?int $weatherCode): static { $this->weatherCode = $weatherCode; return $this; }
    public function getCloudCover(): ?int { return $this->cloudCover; }
    public function setCloudCover(?int $cloudCover): static { $this->cloudCover = $cloudCover; return $this; }
    public function getSurfacePressure(): ?string { return $this->surfacePressure; }
    public function setSurfacePressure(?string $surfacePressure): static { $this->surfacePressure = $surfacePressure; return $this; }
    public function getWindSpeed(): ?string { return $this->windSpeed; }
    public function setWindSpeed(?string $windSpeed): static { $this->windSpeed = $windSpeed; return $this; }
    public function getWindDirection(): ?int { return $this->windDirection; }
    public function setWindDirection(?int $windDirection): static { $this->windDirection = $windDirection; return $this; }
    public function getWindGusts(): ?string { return $this->windGusts; }
    public function setWindGusts(?string $windGusts): static { $this->windGusts = $windGusts; return $this; }
    public function getVisibility(): ?int { return $this->visibility; }
    public function setVisibility(?int $visibility): static { $this->visibility = $visibility; return $this; }
    public function isDay(): ?bool { return $this->isDay; }
    public function setIsDay(?bool $isDay): static { $this->isDay = $isDay; return $this; }
    public function getPayload(): array { return $this->payload; }
    public function setPayload(array $payload): static { $this->payload = $payload; return $this; }
    public function getObservedAt(): \DateTimeImmutable { return $this->observedAt; }
    public function setObservedAt(\DateTimeImmutable $observedAt): static { $this->observedAt = $observedAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }
}
