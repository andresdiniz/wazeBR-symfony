<?php
// src/Entity/WmeUpdateRequest.php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wme_update_request')]
#[ORM\UniqueConstraint(name: 'uniq_wme_ur_id', columns: ['external_id'])]
class WmeUpdateRequest
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'external_id', length: 64)]
    private string $externalId;

    #[ORM\Column(length: 100, nullable: true)] private ?string $type = null;
    #[ORM\Column(length: 32)] private string $status = 'open';
    #[ORM\Column(type: 'text', nullable: true)] private ?string $description = null;
    #[ORM\Column(length: 32, nullable: true)] private ?string $severity = null;
    #[ORM\Column(length: 64, nullable: true)] private ?string $source = null;
    #[ORM\Column(type: 'float')] private float $latitude;
    #[ORM\Column(type: 'float')] private float $longitude;
    #[ORM\Column(type: 'datetime_immutable')] private \DateTimeImmutable $reportedAt;
    #[ORM\Column(type: 'datetime_immutable')] private \DateTimeImmutable $collectedAt;
    #[ORM\Column(type: 'datetime_immutable')] private \DateTimeImmutable $updatedAt;

    public function getExternalId(): string { return $this->externalId; }
    public function setExternalId(string $v): self { $this->externalId = $v; return $this; }
    public function setType(?string $v): self { $this->type = $v; return $this; }
    public function setStatus(string $v): self { $this->status = $v; return $this; }
    public function setDescription(?string $v): self { $this->description = $v; return $this; }
    public function setSeverity(?string $v): self { $this->severity = $v; return $this; }
    public function setSource(?string $v): self { $this->source = $v; return $this; }
    public function setLatitude(float $v): self { $this->latitude = $v; return $this; }
    public function setLongitude(float $v): self { $this->longitude = $v; return $this; }
    public function setReportedAt(\DateTimeImmutable $v): self { $this->reportedAt = $v; return $this; }
    public function setCollectedAt(\DateTimeImmutable $v): self { $this->collectedAt = $v; return $this; }
    public function setUpdatedAt(\DateTimeImmutable $v): self { $this->updatedAt = $v; return $this; }
}

