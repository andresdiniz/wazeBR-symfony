<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TenantAwareTrait;
use App\Repository\MonitoredLinkRepository;
use Doctrine\ORM\Mapping as ORM;

/** Link externo monitorado por parceiro — incluindo feeds de alertas Waze */
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

    #[ORM\Column(length: 500)]
    private string $url = '';

    /** camera | sensor | feed | cemaden | generic */
    #[ORM\Column(length: 40)]
    private string $type = 'generic';

    /** UUID do feed Waze extraído da URL (ex: 9bb3e551-76f2-4fc6-a32e-ad078a285f2e) */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $feedUuid = null;

    /** ID do parceiro Waze extraído da URL (ex: 11682863520) */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $wazePartnerId = null;

    /** Timestamp da última coleta bem-sucedida */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    /** Quantidade de alertas salvos na última coleta */
    #[ORM\Column(nullable: true)]
    private ?int $lastFetchCount = null;

    /** Mensagem de erro da última coleta (null = sem erro) */
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
        // Extrai automaticamente feedUuid e wazePartnerId da URL
        if (preg_match('#/partners/(\d+)/waze-feeds/([0-9a-f-]{36})#i', $u, $m)) {
            $this->wazePartnerId = $m[1];
            $this->feedUuid      = $m[2];
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
}
