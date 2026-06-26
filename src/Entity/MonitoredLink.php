<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MonitoredLinkRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MonitoredLinkRepository::class)]
#[ORM\Table(name: 'monitored_links')]
class MonitoredLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Partner::class, inversedBy: 'links')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Partner $partner;

    /** URL do feed PartnerHub */
    #[ORM\Column(length: 500)]
    private string $url = '';

    /** Rótulo/nome amigável para identificar o feed */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $label = null;

    /** Formato do feed (1 = JSON PartnerHub) */
    #[ORM\Column(options: ['default' => 1])]
    private int $feedFormat = 1;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastCollectedAt = null;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getPartner(): Partner { return $this->partner; }
    public function setPartner(Partner $p): static { $this->partner = $p; return $this; }
    public function getUrl(): string { return $this->url; }
    public function setUrl(string $u): static { $this->url = $u; return $this; }
    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $l): static { $this->label = $l; return $this; }
    public function getFeedFormat(): int { return $this->feedFormat; }
    public function setFeedFormat(int $f): static { $this->feedFormat = $f; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLastCollectedAt(): ?\DateTimeImmutable { return $this->lastCollectedAt; }
    public function setLastCollectedAt(?\DateTimeImmutable $v): static { $this->lastCollectedAt = $v; return $this; }
}
