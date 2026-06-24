<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TenantAwareTrait;
use App\Repository\MonitoredLinkRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Link externo monitorado por parceiro.
 *
 * Types:
 *   feed    → alertas Waze  (row-partnerhub-api/partners/{id}/waze-feeds/{uuid})
 *   traffic → trafego TVT   (row-partnerhub-api/feeds-tvt/{uuid}?id={partnerId})
 *   cemaden → feed CEMADEN
 *   camera  → c\u00e2mera IP
 *   sensor  → sensor IoT
 *   generic → outros
 */
#[ORM\Entity(repositoryClass: MonitoredLinkRepository::class)]
#[ORM\Table(name: 'monitored_links')]
class MonitoredLink
{
    use TenantAwareTrait;

    /** Tipos v\u00e1lidos de MonitoredLink */
    public const TYPES = ['feed', 'traffic', 'cemaden', 'camera', 'sensor', 'generic'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 500)]
    private string $url = '';

    #[ORM\Column(length: 40)]
    private string $type = 'generic';

    /** UUID do feed Waze (alertas ou TVT) extra\u00eddo da URL */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $feedUuid = null;

    /** ID do parceiro Waze extra\u00eddo da URL */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $wazePartnerId = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastFetchCount = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastErrorMessage = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }

    public function getUrl(): string { return $this->url; }
    public function setUrl(string $u): static
    {
        $this->url = $u;
        // Alertas: /partners/{id}/waze-feeds/{uuid}
        if (preg_match('#/partners/(\d+)/waze-feeds/([0-9a-f-]{36})#i', $u, $m)) {
            $this->wazePartnerId = $m[1];
            $this->feedUuid      = $m[2];
        }
        // TVT: /feeds-tvt/{uuid}?id={partnerId}
        elseif (preg_match('#/feeds-tvt/([0-9a-f-]{36})#i', $u, $m)) {
            $this->feedUuid = $m[1];
            if (preg_match('#[?&]id=(\d+)#', $u, $m2)) {
                $this->wazePartnerId = $m2[1];
            }
        }
        return $this;
    }

    public function getType(): string { return $this->type; }
    public function setType(string $t): static { $this->type = $t; return $this; }

    public function getFeedUuid(): ?string { return $this->feedUuid; }
    public function getWazePartnerId(): ?string { return $this->wazePartnerId; }

    public function getLastFetchedAt(): ?\DateTimeImmutable { return $this->lastFetchedAt; }
    public function getLastFetchCount(): ?int { return $this->lastFetchCount; }
    public function getLastErrorMessage(): ?string { return $this->lastErrorMessage; }

    public function markSuccess(int $count): static
    {
        $this->lastFetchedAt    = new \DateTimeImmutable();
        $this->lastFetchCount   = $count;
        $this->lastErrorMessage = null;
        return $this;
    }

    public function markError(string $message): static
    {
        $this->lastFetchedAt    = new \DateTimeImmutable();
        $this->lastErrorMessage = $message;
        return $this;
    }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** Label legivel do tipo para exibir no admin */
    public function getTypeLabel(): string
    {
        return match ($this->type) {
            'feed'    => '\ud83d\udea8 Alertas Waze',
            'traffic' => '\ud83d\ude97 Tr\u00e1fego TVT',
            'cemaden' => '\ud83c\udf27 CEMADEN',
            'camera'  => '\ud83d\udcf7 C\u00e2mera',
            'sensor'  => '\ud83d\udcf1 Sensor',
            default   => '\ud83d\udd17 Gen\u00e9rico',
        };
    }
}
