<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'user')]
#[UniqueEntity(fields: ['email'], message: 'This email is already in use')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_PARTNER_ADMIN = 'ROLE_PARTNER_ADMIN';
    public const ROLE_OPERATOR = 'ROLE_OPERATOR';
    public const ROLE_VIEWER = 'ROLE_VIEWER';

    private const ROLES_REQUIRING_PARTNER = [
        self::ROLE_PARTNER_ADMIN,
        self::ROLE_OPERATOR,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read', 'user:write'])]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['user:read', 'user:write'])]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 255)]
    #[Groups(['user:read', 'user:write'])]
    private ?string $name = null;

    #[ORM\Column(type: Types::JSON, nullable: false)]
    #[Assert\All([
        new Assert\NotBlank(),
        new Assert\Choice(choices: [
            self::ROLE_ADMIN,
            self::ROLE_PARTNER_ADMIN,
            self::ROLE_OPERATOR,
            self::ROLE_VIEWER,
        ]),
    ])]
    #[Groups(['user:read', 'user:write'])]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['user:read', 'user:write'])]
    private ?string $phone = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    #[Groups(['user:read', 'user:write'])]
    private bool $active = true;

    #[ORM\ManyToOne(inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['user:read', 'user:write'])]
    private ?Partner $partner = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\OneToMany(targetEntity: UserPartner::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['user:read'])]
    private Collection $userPartners;

    #[ORM\OneToMany(targetEntity: Alert::class, mappedBy: 'createdBy', orphanRemoval: true)]
    private Collection $alerts;

    public function __construct()
    {
        $this->roles = [self::ROLE_VIEWER];
        $this->createdAt = new \DateTimeImmutable();
        $this->userPartners = new ArrayCollection();
        $this->alerts = new ArrayCollection();
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

    public function setRoles(array $roles): static
    {
        $roles = array_map('strtoupper', $roles);

        $hasRoleRequiringPartner = !empty(array_intersect(self::ROLES_REQUIRING_PARTNER, $roles));
        if ($hasRoleRequiringPartner && !$this->getPartner()) {
            throw new \InvalidArgumentException(
                'Users with ROLE_PARTNER_ADMIN or ROLE_OPERATOR must have a partner associated. ' .
                'Set the partner before assigning these roles.'
            );
        }

        $this->roles = $roles;

        return $this;
    }

    public function addRole(string $role): static
    {
        $role = strtoupper($role);

        if (!in_array($role, [
            self::ROLE_ADMIN,
            self::ROLE_PARTNER_ADMIN,
            self::ROLE_OPERATOR,
            self::ROLE_VIEWER,
        ], true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid role "%s". Allowed roles: %s', $role, implode(', ', [
                    self::ROLE_ADMIN,
                    self::ROLE_PARTNER_ADMIN,
                    self::ROLE_OPERATOR,
                    self::ROLE_VIEWER,
                ]))
            );
        }

        if (!$this->getPartner() && in_array($role, self::ROLES_REQUIRING_PARTNER, true)) {
            throw new \InvalidArgumentException(
                sprintf('Role %s requires a partner association. Set the partner before assigning this role.', $role)
            );
        }

        if (!in_array($role, $this->roles, true)) {
            $this->roles[] = $role;
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

        return $this;
    }

    public function hasRole(string $role): bool
    {
        return in_array(strtoupper($role), $this->getRoles(), true);
    }

    public function isAdmin(): bool
    {
        return in_array(self::ROLE_ADMIN, $this->getRoles(), true);
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

    public function isGlobalAdmin(): bool
    {
        return $this->isAdmin() && $this->getPartner() === null;
    }

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

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    public function setPartner(?Partner $partner): static
    {
        if ($partner === null && $this->getPartner() !== null) {
            $hasRoleRequiringPartner = !empty(array_intersect(self::ROLES_REQUIRING_PARTNER, $this->getRoles()));
            if ($hasRoleRequiringPartner) {
                $this->roles = [self::ROLE_VIEWER];
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

    public function getUserPartners(): Collection
    {
        return $this->userPartners;
    }

    public function addUserPartner(UserPartner $userPartner): static
    {
        if (!$this->userPartners->contains($userPartner)) {
            $this->userPartners->add($userPartner);
            $userPartner->setUser($this);
        }

        return $this;
    }

    public function removeUserPartner(UserPartner $userPartner): static
    {
        if ($this->userPartners->removeElement($userPartner)) {
            if ($userPartner->getUser() === $this) {
                $userPartner->setUser(null);
            }
        }

        return $this;
    }

    public function getAlerts(): Collection
    {
        return $this->alerts;
    }

    public function addAlert(Alert $alert): static
    {
        if (!$this->alerts->contains($alert)) {
            $this->alerts->add($alert);
            $alert->setCreatedBy($this);
        }

        return $this;
    }

    public function removeAlert(Alert $alert): static
    {
        if ($this->alerts->removeElement($alert)) {
            if ($alert->getCreatedBy() === $this) {
                $alert->setCreatedBy(null);
            }
        }

        return $this;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        $hasRoleRequiringPartner = !empty(array_intersect(self::ROLES_REQUIRING_PARTNER, $this->getRoles()));

        if ($hasRoleRequiringPartner && !$this->getPartner()) {
            $context->buildViolation(
                'Users with ROLE_PARTNER_ADMIN or ROLE_OPERATOR must have a partner associated. ' .
                'This is required by PRODUCT_RULES.md'
            )
                ->atPath('partner')
                ->addViolation();
        }
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
}
