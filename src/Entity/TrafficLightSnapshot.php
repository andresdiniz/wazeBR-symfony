<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrafficLightSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TrafficLightSnapshotRepository::class)]
#[ORM\Table(name: 'traffic_light_snapshot')]
#[ORM\Index(name: 'idx_tl_snapshot_light_readat', columns: ['traffic_light_id', 'read_at'])]
class TrafficLightSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TrafficLight::class, inversedBy: 'snapshots')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TrafficLight $trafficLight = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(type: 'boolean')]
    private bool $success = false;

    /** @var array<string,mixed>|null TrafficLightState::toArray() quando success=true. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $state = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $durationMs = null;

    public function getId(): ?int { return $this->id; }

    public function getTrafficLight(): ?TrafficLight { return $this->trafficLight; }

    public function setTrafficLight(?TrafficLight $trafficLight): static
    {
        $this->trafficLight = $trafficLight;
        return $this;
    }

    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }

    public function setReadAt(\DateTimeImmutable $readAt): static
    {
        $this->readAt = $readAt;
        return $this;
    }

    public function isSuccess(): bool { return $this->success; }

    public function setSuccess(bool $success): static
    {
        $this->success = $success;
        return $this;
    }

    /** @return array<string,mixed>|null */
    public function getState(): ?array { return $this->state; }

    /** @param array<string,mixed>|null $state */
    public function setState(?array $state): static
    {
        $this->state = $state;
        return $this;
    }

    public function getErrorMessage(): ?string { return $this->errorMessage; }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage !== null ? mb_substr($errorMessage, 0, 2000) : null;
        return $this;
    }

    public function getDurationMs(): ?int { return $this->durationMs; }

    public function setDurationMs(?int $durationMs): static
    {
        $this->durationMs = $durationMs;
        return $this;
    }

    /** Atalhos para os campos mais consultados do snapshot */
    public function getCurrentPhase(): ?int
    {
        return isset($this->state['currentPhase']) ? (int) $this->state['currentPhase'] : null;
    }

    public function getFaultCount(): int
    {
        return isset($this->state['faults']) && is_array($this->state['faults'])
            ? count($this->state['faults'])
            : 0;
    }
}
