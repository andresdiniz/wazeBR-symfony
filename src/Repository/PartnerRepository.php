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

    public function save(Partner $partner, bool $flush = true): void
    {
        $this->getEntityManager()->persist($partner);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Partner $partner, bool $flush = true): void
    {
        $this->getEntityManager()->remove($partner);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
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

    public function findBySlug(string $slug): ?Partner
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    public function findByApiToken(string $token): ?Partner
    {
        return $this->findOneBy(['apiToken' => $token]);
    }

    public function findAllActive(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.isActive = true')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

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
