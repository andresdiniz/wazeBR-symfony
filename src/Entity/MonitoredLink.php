<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TenantAwareTrait;
use App\Repository\MonitoredLinkRepository;
use Doctrine\ORM\Mapping as ORM;

/** Link externo monitorado por parceiro (câmera, sensor, feed, CEMADEN) */
#[ORM\Entity(repositoryClass: MonitoredLinkRepository::class)]
#[ORM\Table(name: 'monitored_links')]
class MonitoredLink
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 255)]
    private string $url = '';

    /** camera | sensor | feed | cemaden | generic */
    #[ORM\Column(length: 40)]
    private string $type = 'generic';

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }
    public function getUrl(): string { return $this->url; }
    public function setUrl(string $u): static { $this->url = $u; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $t): static { $this->type = $t; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
