<?php

namespace App\Entity;

use App\Repository\CifsEventTypeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CifsEventTypeRepository::class)]
#[ORM\Table(name: 'cifs_event_type')]
#[ORM\UniqueConstraint(name: 'uniq_type_subtype_locale', columns: ['type', 'subtype', 'locale'])]
class CifsEventType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Valor CIFS: HAZARD, ACCIDENT, ROAD_CLOSED, etc. */
    #[ORM\Column(length: 60)]
    private string $type;

    /** Valor CIFS: HAZARD_ON_ROAD, ACCIDENT_MINOR, etc. Null = representa o tipo pai. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $subtype = null;

    /** ISO 639-1: pt, en, es, fr … */
    #[ORM\Column(length: 5)]
    private string $locale;

    /** Rótulo traduzido para exibição */
    #[ORM\Column(length: 120)]
    private string $label;

    /** Descrição auxiliar opcional */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    // ── Getters & Setters ─────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getSubtype(): ?string { return $this->subtype; }
    public function setSubtype(?string $subtype): static { $this->subtype = $subtype; return $this; }

    public function getLocale(): string { return $this->locale; }
    public function setLocale(string $locale): static { $this->locale = $locale; return $this; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): static { $this->label = $label; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
}
