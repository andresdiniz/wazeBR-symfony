<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Hierarquia de roles:
 *
 *  ROLE_SUPER_ADMIN   → acesso total à plataforma, cria/gerencia parceiros
 *  ROLE_ACCOUNT_ADMIN → administrador do parceiro ao qual pertence
 *  ROLE_USER          → visualização dos dados do parceiro
 *  ROLE_FIELD_AGENT   → agente de via; permissões configuráveis via fieldAgentPermissions
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'Este email já está em uso.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /**
     * Parceiro (tenant) ao qual este usuário pertence.
     * Nullable: ROLE_SUPER_ADMIN não possui parceiro vinculado.
     */
    #[ORM\ManyToOne(targetEntity: Partner::class, inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Partner $partner = null;

    /**
     * Permissões customizadas para agentes de via (ROLE_FIELD_AGENT).
     * Armazenadas como JSON. Ex: ['view_alerts', 'view_jams', 'submit_report']
     * Se vazio/null, o agente recebe as mesmas permissões de ROLE_USER.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $fieldAgentPermissions = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getUserIdentifier(): string { return $this->email; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function setLastLoginAt(\DateTimeImmutable $lastLoginAt): static { $this->lastLoginAt = $lastLoginAt; return $this; }

    public function eraseCredentials(): void {}

    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): static { $this->partner = $partner; return $this; }

    public function getFieldAgentPermissions(): ?array { return $this->fieldAgentPermissions; }
    public function setFieldAgentPermissions(?array $fieldAgentPermissions): static
    {
        $this->fieldAgentPermissions = $fieldAgentPermissions;
        return $this;
    }

    // ── Role helpers ─────────────────────────────────────────────────────────

    /** Acesso total à plataforma; cria e gerencia parceiros. Sem parceiro vinculado. */
    public function isSuperAdmin(): bool
    {
        return in_array('ROLE_SUPER_ADMIN', $this->getRoles(), true);
    }

    /** Administrador do parceiro; gerencia usuários e configurações do próprio parceiro. */
    public function isAccountAdmin(): bool
    {
        return in_array('ROLE_ACCOUNT_ADMIN', $this->getRoles(), true);
    }

    /** Usuário padrão; visualiza dados do parceiro ao qual pertence. */
    public function isRegularUser(): bool
    {
        return !$this->isSuperAdmin() && !$this->isAccountAdmin() && !$this->isFieldAgent();
    }

    /** Agente de via; permissões configuráveis individualmente. */
    public function isFieldAgent(): bool
    {
        return in_array('ROLE_FIELD_AGENT', $this->getRoles(), true);
    }

    /**
     * Verifica se o agente de via possui uma permissão específica.
     * Se fieldAgentPermissions for null/vazio, retorna true (acesso igual a ROLE_USER).
     */
    public function hasFieldAgentPermission(string $permission): bool
    {
        if (empty($this->fieldAgentPermissions)) {
            return true;
        }

        return in_array($permission, $this->fieldAgentPermissions, true);
    }

    /**
     * Garante que o usuário pertence ao mesmo parceiro que o recurso solicitado.
     * ROLE_SUPER_ADMIN passa automaticamente (sem restrição de parceiro).
     */
    public function belongsToPartner(Partner $partner): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->partner !== null && $this->partner->getId() === $partner->getId();
    }
}
