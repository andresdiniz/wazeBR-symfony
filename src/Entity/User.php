<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'user')]
#[UniqueEntity(fields: ['email'], message: 'This email is already registered.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_ACCOUNT_ADMIN = 'ROLE_ACCOUNT_ADMIN';
    public const ROLE_FIELD_AGENT = 'ROLE_FIELD_AGENT';
    public const ROLE_PARTNER_ADMIN = 'ROLE_PARTNER_ADMIN';
    public const ROLE_OPERATOR = 'ROLE_OPERATOR';
    public const ROLE_VIEWER = 'ROLE_VIEWER';

    /**
     * Roles that require the user to be associated with a partner.
     * Global admins (ROLE_ADMIN without partner) are allowed to have partner === null.
     */
    private const ROLES_REQUIRING_PARTNER = [self::ROLE_PARTNER_ADMIN, self::ROLE_OPERATOR, self::ROLE_FIELD_AGENT];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['user:read', 'user:write'])]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['user:read', 'user:write'])]
    private ?string $name = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    #[Groups(['user:read', 'user:write'])]
    private ?string $phone = null;

    /**
     * Partner association.
     *
     * Business rule:
     * - Global admins (ROLE_ADMIN without partner) MAY have partner === null.
     * - Partner administrators, operators, and field agents MUST have a partner.
     * - This is enforced in setPartner(), setRoles() and addRole().
     */
    #[ORM\ManyToOne(inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['user:read', 'user:write'])]
    private ?Partner $partner = null;

    #[ORM\Column(type: 'datetime_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    public function __construct(?Partner $partner = null)
    {
        $this->roles = [self::ROLE_VIEWER];
        $this->createdAt = new \DateTimeImmutable();

        if ($partner !== null) {
            $this->setPartner($partner);
        }
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
        if (empty($roles)) {
            $roles[] = self::ROLE_VIEWER;
        }
        return array_unique($roles);
    }

    /**
     * Set roles with validation.
     *
     * Business rule:
     * - Partner administrators, operators, and field agents require a partner.
     *   the user MUST have a partner associated.
     * - Global admins (ROLE_ADMIN) are allowed to have partner === null.
     */
    public function setRoles(array $roles): static
    {
        $roles = array_map(static fn (string $role): string => match (strtoupper($role)) {
            'ROLE_USER' => self::ROLE_VIEWER,
            'ROLE_ADMIN_PARTNER' => self::ROLE_PARTNER_ADMIN,
            default => strtoupper($role),
        }, $roles);
        if ($roles === []) {
            $roles = [self::ROLE_VIEWER];
        }
        $allowedRoles = [self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN, self::ROLE_ACCOUNT_ADMIN, self::ROLE_PARTNER_ADMIN, self::ROLE_FIELD_AGENT, self::ROLE_OPERATOR, self::ROLE_VIEWER];
        if (array_diff($roles, $allowedRoles) !== []) {
            throw new \InvalidArgumentException('The selected user profile is not supported.');
        }
        if (array_intersect([self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN], $roles) !== [] && $this->getPartner() !== null) {
            throw new \InvalidArgumentException('Global administrators cannot be linked to a partner.');
        }
        if (!empty(array_intersect(self::ROLES_REQUIRING_PARTNER, $roles)) && !$this->getPartner()) {
            throw new \InvalidArgumentException('This user profile requires a partner.');
        }
        $this->roles = array_values(array_unique($roles ?: [self::ROLE_VIEWER]));
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Add a single role with validation.
     *
     * Business rule:
     * - Partner administrator, operator, and field agent profiles require a partner.
     * - Attempting to add such a role without a partner throws an exception.
     */
    public function setAccessProfile(?Partner $partner, array $roles): static
    {
        $normalizedRoles = array_map(static fn (string $role): string => match (strtoupper($role)) {
            'ROLE_USER' => self::ROLE_VIEWER,
            'ROLE_ADMIN_PARTNER' => self::ROLE_PARTNER_ADMIN,
            default => strtoupper($role),
        }, $roles);
        if ($normalizedRoles === []) {
            $normalizedRoles = [self::ROLE_VIEWER];
        }
        if (array_intersect([self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN], $normalizedRoles) !== [] && $partner !== null) {
            throw new \InvalidArgumentException('Global administrators cannot be linked to a partner.');
        }
        if (!empty(array_intersect(self::ROLES_REQUIRING_PARTNER, $normalizedRoles)) && $partner === null) {
            throw new \InvalidArgumentException('This user profile requires a partner.');
        }

        $this->partner = $partner;
        return $this->setRoles($normalizedRoles);
    }
    public function addRole(string $role): static
    {
        $role = strtoupper($role);

        if (in_array($role, [self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN], true) && $this->getPartner() !== null) {
            throw new \InvalidArgumentException('Global administrators cannot be linked to a partner.');
        }

        if (!in_array($role, [self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN, self::ROLE_ACCOUNT_ADMIN, self::ROLE_PARTNER_ADMIN, self::ROLE_FIELD_AGENT, self::ROLE_OPERATOR, self::ROLE_VIEWER], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid role "%s"', $role));
        }

        if (!$this->getPartner() && in_array($role, self::ROLES_REQUIRING_PARTNER, true)) {
            throw new \InvalidArgumentException(sprintf('Role %s requires a partner association', $role));
        }

        if (!in_array($role, $this->roles, true)) {
            $this->roles[] = $role;
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function removeRole(string $role): static
    {
        $role = strtoupper($role);
        $key = array_search($role, $this->roles, true);
        if ($key !== false) {
            unset($this->roles[$key]);
            $this->roles = array_values($this->roles);
        }

        if (empty($this->roles)) {
            $this->roles[] = self::ROLE_VIEWER;
        }

        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function hasRole(string $role): bool
    {
        return in_array(strtoupper($role), $this->getRoles(), true);
    }

    public function isAdmin(): bool
    {
        return in_array(self::ROLE_ADMIN, $this->getRoles(), true) || in_array(self::ROLE_SUPER_ADMIN, $this->getRoles(), true);
    }

    public function isAccountAdmin(): bool
    {
        return in_array(self::ROLE_ACCOUNT_ADMIN, $this->getRoles(), true);
    }

    public function isFieldAgent(): bool
    {
        return in_array(self::ROLE_FIELD_AGENT, $this->getRoles(), true);
    }

    public function isPartnerAdmin(): bool
    {
        return in_array(self::ROLE_PARTNER_ADMIN, $this->getRoles(), true);
    }

    public function isOperator(): bool
    {
        return in_array(self::ROLE_OPERATOR, $this->getRoles(), true);
    }

    public function isViewer(): bool
    {
        return in_array(self::ROLE_VIEWER, $this->getRoles(), true);
    }

    /**
     * Global admin: ROLE_ADMIN and no partner.
     */
    public function isGlobalAdmin(): bool
    {
        return $this->isAdmin() && $this->getPartner() === null;
    }

    /**
     * Partner-scoped user: has any partner association.
     */
    public function isPartnerScoped(): bool
    {
        return $this->getPartner() !== null;
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

    public function eraseCredentials(): void
    {
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

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;
        return $this;
    }

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $active): static
    {
        return $this->setActive($active);
    }
    public function setActive(bool $active): static
    {
        if ($this->isActive === $active) {
            return $this;
        }

        $this->isActive  = $active;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    /**
     * Set partner with business-rule validation.
     *
     * Rules:
     * - Global admins (ROLE_ADMIN without partner) are allowed to have partner === null.
     * - Partner administrators, operators, and field agents MUST have a partner.
     * - Removing partner from such a user throws a LogicException.
     */
    public function setPartner(?Partner $partner): static
    {
        if ($partner !== null && $this->isAdmin()) {
            throw new \InvalidArgumentException('Global administrators cannot be linked to a partner.');
        }
        // Prevent removing partner from users that require one
        if ($partner === null && $this->partner !== null) {
            $hasRoleRequiringPartner = !empty(array_intersect(self::ROLES_REQUIRING_PARTNER, $this->getRoles()));
            if ($hasRoleRequiringPartner) {
                throw new \LogicException(
                    'Cannot remove a required partner from this profile.'
                );
            }
        }

        // If assigning a partner-requirement role without a partner, that's invalid
        // (This case should be caught earlier by setRoles/addRole, but we double-check.)
        if ($partner === null) {
            $hasRoleRequiringPartner = !empty(array_intersect(self::ROLES_REQUIRING_PARTNER, $this->getRoles()));
            if ($hasRoleRequiringPartner) {
                // Option 1: throw exception (stricter)
                throw new \LogicException(
                    'Partner administrator, operator, and field agent profiles must have a partner associated.'
                );
                // Option 2 (alternative): automatically downgrade roles to ROLE_VIEWER
                // $this->roles = [self::ROLE_VIEWER];
            }
        }

        $this->partner = $partner;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
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

    public function recordLogin(): static
    {
        $this->lastLoginAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s (%s) - Roles: %s, Partner: %s',
            $this->name ?? 'Unknown',
            $this->email ?? 'No email',
            implode(', ', $this->getRoles()),
            $this->getPartner()?->getName() ?? 'None (Global)'
        );
    }

    // Field for forms only, not persisted
    private ?string $plainPassword = null;

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;
        return $this;
    }


}
