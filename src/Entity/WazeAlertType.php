<?php

namespace App\Entity;

use App\Repository\WazeAlertTypeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tradução de type/subtype do feed de LEITURA do Waze (Traffic View / WazeAlert),
 * que usa uma taxonomia diferente da spec CIFS de envio (ver CifsEventType).
 * @see https://support.google.com/waze/partners/answer/13658466
 */
#[ORM\Entity(repositoryClass: WazeAlertTypeRepository::class)]
#[ORM\Table(name: 'waze_alert_type')]
#[ORM\UniqueConstraint(name: 'uniq_walert_type_subtype_locale', columns: ['type', 'subtype', 'locale'])]
class WazeAlertType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** ACCIDENT, JAM, WEATHERHAZARD, HAZARD, MISC, CONSTRUCTION, ROAD_CLOSED */
    #[ORM\Column(length: 60)]
    private string $type;

    /** Ex: ACCIDENT_MINOR, HAZARD_ON_ROAD_POT_HOLE, NO_SUBTYPE. Null = representa o tipo pai. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $subtype = null;

    /** ISO 639-1: pt, en, es … */
    #[ORM\Column(length: 5)]
    private string $locale;

    /** Rótulo traduzido para exibição */
    #[ORM\Column(length: 120)]
    private string $label;

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
}
