<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeTvtRouteForecastRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeTvtRouteForecastRepository::class)]
#[ORM\Table(name: 'waze_tvt_route_forecast')]
#[ORM\UniqueConstraint(name: 'uniq_tvt_route_forecast_slot', columns: ['route_id', 'forecast_at'])]
#[ORM\Index(name: 'idx_tvt_route_forecast_lookup', columns: ['forecast_at', 'risk_level'])]
#[ORM\Index(name: 'idx_tvt_route_forecast_route', columns: ['route_id', 'forecast_at'])]
class WazeTvtRouteForecast
{
    public const LEVEL_LOW = 'low';
    public const LEVEL_MEDIUM = 'medium';
    public const LEVEL_HIGH = 'high';
    public const LEVEL_CRITICAL = 'critical';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WazeTvtRoute::class)]
    #[ORM\JoinColumn(name: 'route_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?WazeTvtRoute $route = null;

    #[ORM\Column(name: 'forecast_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $forecastAt = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $weekday = 0;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $hour = 0;

    #[ORM\Column(name: 'slot_minutes', type: Types::SMALLINT)]
    private int $slotMinutes = 30;

    #[ORM\Column(name: 'avg_time', type: Types::FLOAT, nullable: true)]
    private ?float $avgTime = null;

    #[ORM\Column(name: 'avg_historic_time', type: Types::FLOAT, nullable: true)]
    private ?float $avgHistoricTime = null;

    #[ORM\Column(name: 'avg_jam_level', type: Types::FLOAT, nullable: true)]
    private ?float $avgJamLevel = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $samples = 0;

    #[ORM\Column(name: 'risk_score', type: Types::FLOAT)]
    private float $riskScore = 0.0;

    #[ORM\Column(name: 'risk_level', length: 10)]
    private string $riskLevel = self::LEVEL_LOW;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoute(): ?WazeTvtRoute
    {
        return $this->route;
    }

    public function setRoute(?WazeTvtRoute $route): static
    {
        $this->route = $route;

        return $this;
    }

    public function getForecastAt(): ?\DateTimeImmutable
    {
        return $this->forecastAt;
    }

    public function setForecastAt(\DateTimeImmutable $forecastAt): static
    {
        $this->forecastAt = $forecastAt;
        $this->weekday = (int) $forecastAt->format('w');
        $this->hour = (int) $forecastAt->format('G');

        return $this;
    }

    public function getWeekday(): int
    {
        return $this->weekday;
    }

    public function getHour(): int
    {
        return $this->hour;
    }

    public function getSlotMinutes(): int
    {
        return $this->slotMinutes;
    }

    public function setSlotMinutes(int $slotMinutes): static
    {
        $this->slotMinutes = $slotMinutes;

        return $this;
    }

    public function getAvgTime(): ?float
    {
        return $this->avgTime;
    }

    public function setAvgTime(?float $avgTime): static
    {
        $this->avgTime = $avgTime;

        return $this;
    }

    public function getAvgHistoricTime(): ?float
    {
        return $this->avgHistoricTime;
    }

    public function setAvgHistoricTime(?float $avgHistoricTime): static
    {
        $this->avgHistoricTime = $avgHistoricTime;

        return $this;
    }

    public function getAvgJamLevel(): ?float
    {
        return $this->avgJamLevel;
    }

    public function setAvgJamLevel(?float $avgJamLevel): static
    {
        $this->avgJamLevel = $avgJamLevel;

        return $this;
    }

    public function getSamples(): int
    {
        return $this->samples;
    }

    public function setSamples(int $samples): static
    {
        $this->samples = $samples;

        return $this;
    }

    public function getRiskScore(): float
    {
        return $this->riskScore;
    }

    public function setRiskScore(float $riskScore): static
    {
        $this->riskScore = round(max(0.0, min(100.0, $riskScore)), 2);

        return $this;
    }

    public function getRiskLevel(): string
    {
        return $this->riskLevel;
    }

    public function setRiskLevel(string $riskLevel): static
    {
        $this->riskLevel = $riskLevel;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getRiskLabel(): string
    {
        return match ($this->riskLevel) {
            self::LEVEL_CRITICAL => 'Congestionamento provável',
            self::LEVEL_HIGH => 'Risco alto de lentidão',
            self::LEVEL_MEDIUM => 'Tráfego pode piorar',
            default => 'Tráfego tende a fluir',
        };
    }
}
