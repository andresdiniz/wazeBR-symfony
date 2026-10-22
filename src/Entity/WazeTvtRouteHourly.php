<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Agregado HORÁRIO (UTC) dos snapshots de uma rota TVT.
 *
 * Preenchida pelo comando `app:routes:rollup-hourly` (INSERT … SELECT … ON DUPLICATE
 * KEY UPDATE, idempotente). Guarda SOMAS e CONTAGENS — nunca médias — para que
 * qualquer agrupamento posterior (hora do dia, dia da semana, 30 dias) seja uma média
 * ponderada exata, idêntica à calculada sobre os snapshots brutos.
 *
 * Semântica das colunas (todas sobre snapshots com `time` e `historic_time` não nulos,
 * exceto jam_0..jam_5, que contam todo snapshot com `jam_level`):
 *   samples    — nº de snapshots válidos
 *   sum_delay  — Σ (time − historic_time), em segundos (pode ser negativo)
 *   sum_ratio  — Σ (time − historic_time) / historic_time, só onde historic_time > 0
 *   n_ratio    — nº de snapshots que entram em sum_ratio
 *   sum_jam    — Σ jam_level; n_jam — nº de snapshots com jam_level
 *   jam_0..5   — contagem por nível de congestionamento
 */
#[ORM\Entity]
#[ORM\Table(name: 'waze_tvt_route_hourly')]
#[ORM\UniqueConstraint(name: 'uniq_route_hourly_bucket', columns: ['route_id', 'bucket_start'])]
#[ORM\Index(name: 'idx_route_hourly_partner_bucket', columns: ['partner_id', 'bucket_start'])]
class WazeTvtRouteHourly
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'route_id')]
    private int $routeId;

    #[ORM\Column(name: 'partner_id')]
    private int $partnerId;

    /** Início da hora, em UTC. */
    #[ORM\Column(name: 'bucket_start', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $bucketStart;

    #[ORM\Column]
    private int $samples = 0;

    #[ORM\Column(name: 'sum_delay', type: Types::FLOAT)]
    private float $sumDelay = 0.0;

    #[ORM\Column(name: 'sum_ratio', type: Types::FLOAT)]
    private float $sumRatio = 0.0;

    #[ORM\Column(name: 'n_ratio')]
    private int $nRatio = 0;

    #[ORM\Column(name: 'sum_jam')]
    private int $sumJam = 0;

    #[ORM\Column(name: 'n_jam')]
    private int $nJam = 0;

    #[ORM\Column(name: 'jam_0')]
    private int $jam0 = 0;

    #[ORM\Column(name: 'jam_1')]
    private int $jam1 = 0;

    #[ORM\Column(name: 'jam_2')]
    private int $jam2 = 0;

    #[ORM\Column(name: 'jam_3')]
    private int $jam3 = 0;

    #[ORM\Column(name: 'jam_4')]
    private int $jam4 = 0;

    #[ORM\Column(name: 'jam_5')]
    private int $jam5 = 0;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->bucketStart = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRouteId(): int
    {
        return $this->routeId;
    }

    public function getBucketStart(): \DateTimeImmutable
    {
        return $this->bucketStart;
    }

    public function getSamples(): int
    {
        return $this->samples;
    }
}
