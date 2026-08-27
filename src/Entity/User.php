<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface as CoreUserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[UniqueEntity(fields: ['email'], message: 'Este email já está em uso')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(type: 'string')]
    private ?string $password = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $resetToken = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resetTokenExpiresAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Partner $partner = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $lastLoginIp = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $fieldAgentPermissions = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // Não há dados sens�veis tempor�rios para limpar
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getResetToken(): ?string
    {
        return $this->resetToken;
    }

    public function setResetToken(?string $resetToken): static
    {
        $this->resetToken = $resetToken;
        return $this;
    }

    public function getResetTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->resetTokenExpiresAt;
    }

    public function setResetTokenExpiresAt(?\DateTimeImmutable $resetTokenExpiresAt): static
    {
        $this->resetTokenExpiresAt = $resetTokenExpiresAt;
        return $this;
    }

    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    public function setPartner(?Partner $partner): static
    {
        $this->partner = $partner;
        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;
        return $this;
    }

    public function getLastLoginIp(): ?string
    {
        return $this->lastLoginIp;
    }

    public function setLastLoginIp(?string $lastLoginIp): static
    {
        $this->lastLoginIp = $lastLoginIp;
        return $this;
    }

    /**
     * @return string[]|null
     */
    public function getFieldAgentPermissions(): ?array
    {
        return $this->fieldAgentPermissions;
    }

    /**
     * @param string[]|null $permissions
     */
    public function setFieldAgentPermissions(?array $permissions): static
    {
        $this->fieldAgentPermissions = $permissions;
        return $this;
    }

    /**
     * Alias de isActive() — usado no fluxo de "esqueci minha senha"
     * (AuthController::forgot()) para decidir se envia o e-mail de reset.
     *
     * NOTA: era chamado sem existir aqui, causando erro fatal
     * (Call to undefined method) toda vez que alguém tentava
     * redefinir a senha — mesmo padrão de bug já corrigido em
     * outros pontos do projeto (métodos chamados sem implementação).
     */
    public function isEnabled(): bool
    {
        return $this->isActive();
    }

    public function isSuperAdmin(): bool
    {
        return in_array('ROLE_SUPER_ADMIN', $this->getRoles(), true);
    }

    public function isAccountAdmin(): bool
    {
        return in_array('ROLE_ACCOUNT_ADMIN', $this->getRoles(), true);
    }

    /**
     * Usado por AccountUserController para garantir que um admin de conta
     * só edite/desative/remova usuários do próprio parceiro.
     *
     * NOTA: isSuperAdmin(), isAccountAdmin() e belongsToPartner() eram
     * chamados em AccountUserController sem existir aqui — toda a área
     * de gestão de usuários da conta (/account/users/*) dava erro fatal
     * antes desta correção.
     */
    public function belongsToPartner(?Partner $partner): bool
    {
        return $this->partner !== null
            && $partner !== null
            && $this->partner->getId() === $partner->getId();
    }

    public function isFieldAgent(): bool
    {
        return in_array('ROLE_FIELD_AGENT', $this->getRoles(), true);
    }

    /**
     * Usado por FieldAgentVoter para checar permissões granulares de um
     * agente de campo (FieldAgentVoter::PERMISSIONS). Quando
     * $fieldAgentPermissions é null, o agente não tem nenhuma permissão
     * customizada liberada (mais restritivo por padrão).
     *
     * NOTA: isFieldAgent() e hasFieldAgentPermission() eram chamados em
     * FieldAgentVoter sem existir aqui — todo o controle de acesso de
     * agentes de campo (rotas FIELD_AGENT_*) dava erro fatal antes desta
     * correção, negando acesso a todo mundo (inclusive admins, que
     * dependem do voter avaliar sem quebrar antes de cair no atalho de
     * isSuperAdmin()/isAccountAdmin()).
     */
    public function hasFieldAgentPermission(string $permission): bool
    {
        return $this->fieldAgentPermissions !== null
            && in_array($permission, $this->fieldAgentPermissions, true);
    }

    public function isEqualTo(CoreUserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        return $this->id === $user->id;
    }
}
