<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Rota TVT marcada como favorita por um usuário.
 * Usada para destacar rotas na lista "Comparar com…" da página de detalhe.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_favorite_route')]
#[ORM\UniqueConstraint(name: 'uniq_user_favorite_route', columns: ['user_id', 'route_id'])]
class UserFavoriteRoute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: WazeTvtRoute::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?WazeTvtRoute $route = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getRoute(): ?WazeTvtRoute
    {
        return $this->route;
    }

    public function setRoute(WazeTvtRoute $route): static
    {
        $this->route = $route;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
