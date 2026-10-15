<?php

declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;

#[ORM\Entity]
#[ORM\Table(name: 'waze_tvt_forecast_audit')]
#[ORM\UniqueConstraint(name: 'uniq_forecast_audit_issue', columns: ['source_id', 'issued_at'])]
#[ORM\Index(name: 'idx_forecast_audit_target', columns: ['target_start_at', 'route_id'])]
class WazeTvtForecastAudit
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(name: 'source_id')]
    private int $sourceId;
    #[ORM\Column(name: 'route_id')]
    private int $routeId;
    #[ORM\Column(name: 'issued_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $issuedAt;
    #[ORM\Column(name: 'captured_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $capturedAt;
    #[ORM\Column(name: 'target_start_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $targetStartAt;
    #[ORM\Column(name: 'target_end_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $targetEndAt;
    #[ORM\Column(name: 'horizon_minutes', type: Types::FLOAT)]
    private float $horizonMinutes;
    #[ORM\Column(name: 'predicted_time', type: Types::FLOAT)]
    private float $predictedTime;
    #[ORM\Column(name: 'history_samples')]
    private int $historySamples;
    #[ORM\Column(name: 'actual_time', type: Types::FLOAT, nullable: true)]
    private ?float $actualTime = null;
    #[ORM\Column(name: 'actual_samples')]
    private int $actualSamples = 0;
    #[ORM\Column(name: 'error_seconds', type: Types::FLOAT, nullable: true)]
    private ?float $errorSeconds = null;
    #[ORM\Column(name: 'within_tolerance', nullable: true)]
    private ?bool $withinTolerance = null;
    #[ORM\Column(length: 20)]
    private string $status = 'pending';
    #[ORM\Column(name: 'evaluated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $evaluatedAt = null;
    public function getId(): ?int { return $this->id; }
}
