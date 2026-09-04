<?php

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

    public function findAllWithUserCount(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('App\Entity\User', 'u', 'ON', 'u.partner = p')
            ->addSelect('COUNT(u.id) AS userCount')
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
            ->where('p.active = true')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findActivePartners(): array
    {
        return $this->findAllActive();
    }

    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.active = true')
            ->getQuery()
            ->getSingleScalarResult();
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

    public function getEntityManager(): \Doctrine\ORM\EntityManagerInterface
    {
        return parent::getEntityManager();
    }
}
