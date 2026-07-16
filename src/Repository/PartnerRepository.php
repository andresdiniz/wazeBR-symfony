<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Partner>
 */
class PartnerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Partner::class);
    }

    /**
     * Lista todos os parceiros com contagem de usuários, ordenados por nome.
     */
    public function findAllWithUserCount(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.users', 'u')
            ->addSelect('COUNT(u.id) AS HIDDEN userCount')
            ->groupBy('p.id')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Busca parceiro pelo slug.
     */
    public function findBySlug(string $slug): ?Partner
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Busca parceiro pelo API token.
     */
    public function findByApiToken(string $token): ?Partner
    {
        return $this->findOneBy(['apiToken' => $token]);
    }

    /**
     * Lista apenas parceiros ativos.
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.isActive = true')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Busca parceiros com links monitorados carregados em JOIN.
     */
    public function findWithLinks(int $id): ?Partner
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.links', 'l')
            ->addSelect('l')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
