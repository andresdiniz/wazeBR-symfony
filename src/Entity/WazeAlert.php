<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeAlertRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeAlertRepository::class)]
#[ORM\Table(name: 'waze_alerts')]
#[ORM\Index(name: 'idx_type_city', columns: ['type', 'city'])]
#[ORM\Index(name: 'idx_pubmillis', columns: ['pub_millis'])]
#[ORM\HasLifecycleCallbacks]
class WazeAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $wazeId;

    #[ORM\Column(length: 60)]
    private string $type;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $subtype = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7)]
    private float $latitude;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7)]
    private float $longitude;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(nullable: true)]
    private ?int $reliability = null;

    #[ORM\Column(nullable: true)]
    private ?int $confidence = null;

    #[ORM\Column(nullable: true)]
    private ?int $reportRating = null;

    #[ORM\Column(type: 'bigint')]
    private int $pubMillis;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void { $this->createdAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getWazeId(): string { return $this->wazeId; }
    public function setWazeId(string $wazeId): static { $this->wazeId = $wazeId; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }
    public function getSubtype(): ?string { return $this->subtype; }
    public function setSubtype(?string $subtype): static { $this->subtype = $subtype; return $this; }
    public function getLatitude(): float { return $this->latitude; }
    public function setLatitude(float $latitude): static { $this->latitude = $latitude; return $this; }
    public function getLongitude(): float { return $this->longitude; }
    public function setLongitude(float $longitude): static { $this->longitude = $longitude; return $this; }
    public function getStreet(): ?string { return $this->street; }
    public function setStreet(?string $street): static { $this->street = $street; return $this; }
    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): static { $this->city = $city; return $this; }
    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $country): static { $this->country = $country; return $this; }
    public function getReliability(): ?int { return $this->reliability; }
    public function setReliability(?int $reliability): static { $this->reliability = $reliability; return $this; }
    public function getConfidence(): ?int { return $this->confidence; }
    public function setConfidence(?int $confidence): static { $this->confidence = $confidence; return $this; }
    public function getReportRating(): ?int { return $this->reportRating; }
    public function setReportRating(?int $reportRating): static { $this->reportRating = $reportRating; return $this; }
    public function getPubMillis(): int { return $this->pubMillis; }
    public function setPubMillis(int $pubMillis): static { $this->pubMillis = $pubMillis; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
