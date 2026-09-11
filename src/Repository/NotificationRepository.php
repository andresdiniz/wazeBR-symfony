<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function countUnreadByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.partner = :partner')
            ->andWhere('n.isRead = :isRead')
            ->setParameter('partner', $partner)
            ->setParameter('isRead', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findByPartner(Partner $partner, int $limit = 10): array
    {
        return $this->createQueryBuilder('n')
            ->select('n')
            ->where('n.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return Notification[] Returns an array of Notification objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('n')
    //            ->andWhere('n.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('n.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function save(Notification $entity, bool $flush = false): void
    //    {
    //        $this->getEntityManager()->persist($entity);
    //
    //        if ($flush) {
    //            $this->getEntityManager()->flush();
    //        }
    //    }

    //    public function remove(Notification $entity, bool $flush = false): void
    //    {
    //        $this->getEntityManager()->remove($entity);
    //
    //        if ($flush) {
    //            $this->getEntityManager()->flush();
    //        }
    //    }
}
