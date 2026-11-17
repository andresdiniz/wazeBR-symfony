<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserNotificationPreferenceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Preferências de e-mail de um usuário: chave geral + um interruptor por categoria.
 * Os tipos/subtipos escolhidos ficam em UserNotificationSubscription.
 */
#[ORM\Entity(repositoryClass: UserNotificationPreferenceRepository::class)]
#[ORM\Table(name: 'user_notification_preference')]
#[ORM\UniqueConstraint(name: 'uniq_user_notification_preference_user', columns: ['user_id'])]
class UserNotificationPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Chave geral: desligada = nenhum e-mail de notificação, de nenhuma categoria. */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $emailEnabled = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $alertsEnabled = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $eventsEnabled = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $briefingEnabled = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, UserNotificationSubscription> */
    #[ORM\OneToMany(mappedBy: 'preference', targetEntity: UserNotificationSubscription::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $subscriptions;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
        $this->subscriptions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function isEmailEnabled(): bool
    {
        return $this->emailEnabled;
    }

    public function setEmailEnabled(bool $emailEnabled): static
    {
        $this->emailEnabled = $emailEnabled;
        $this->touch();

        return $this;
    }

    public function isAlertsEnabled(): bool
    {
        return $this->alertsEnabled;
    }

    public function setAlertsEnabled(bool $alertsEnabled): static
    {
        $this->alertsEnabled = $alertsEnabled;
        $this->touch();

        return $this;
    }

    public function isEventsEnabled(): bool
    {
        return $this->eventsEnabled;
    }

    public function setEventsEnabled(bool $eventsEnabled): static
    {
        $this->eventsEnabled = $eventsEnabled;
        $this->touch();

        return $this;
    }

    public function isBriefingEnabled(): bool
    {
        return $this->briefingEnabled;
    }

    public function setBriefingEnabled(bool $briefingEnabled): static
    {
        $this->briefingEnabled = $briefingEnabled;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, UserNotificationSubscription> */
    public function getSubscriptions(): Collection
    {
        return $this->subscriptions;
    }

    /**
     * Regras de uma categoria no formato [TIPO => [SUBTIPO|'*', ...]].
     *
     * @return array<string, list<string>>
     */
    public function rulesFor(string $category): array
    {
        $rules = [];
        foreach ($this->subscriptions as $subscription) {
            if ($subscription->getCategory() !== $category) {
                continue;
            }
            $rules[$subscription->getType()][] = $subscription->getSubtype();
        }

        return $rules;
    }

    /**
     * Substitui TODAS as regras de uma categoria pelas informadas.
     *
     * Faz a diferença (mantém o que não mudou, remove o que saiu, insere o que entrou) em vez
     * de apagar e recriar: o Doctrine executa os INSERTs antes dos DELETEs, e recriar uma regra
     * igual no mesmo flush violaria a restrição única.
     *
     * @param array<string, list<string>> $rules [TIPO => [SUBTIPO|'*', ...]]
     */
    public function replaceRules(string $category, array $rules): static
    {
        $wanted = [];
        foreach ($rules as $type => $subtypes) {
            foreach ($subtypes as $subtype) {
                $subtype = $subtype === UserNotificationSubscription::WILDCARD ? $subtype : strtoupper($subtype);
                $wanted[strtoupper((string) $type) . "\0" . $subtype] = [strtoupper((string) $type), $subtype];
            }
        }

        foreach ($this->subscriptions->toArray() as $subscription) {
            if ($subscription->getCategory() !== $category) {
                continue;
            }
            $key = $subscription->getType() . "\0" . $subscription->getSubtype();
            if (isset($wanted[$key])) {
                unset($wanted[$key]); // já existe: mantém
            } else {
                $this->subscriptions->removeElement($subscription);
            }
        }

        foreach ($wanted as [$type, $subtype]) {
            $this->subscriptions->add(new UserNotificationSubscription($this, $category, $type, $subtype));
        }

        $this->touch();

        return $this;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
