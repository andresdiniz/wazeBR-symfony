<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonitoredLink;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MonitoredLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonitoredLink::class);
    }

    public function findByPartner(Partner $partner, ?string $type = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->where('l.partner = :partner')
            ->andWhere('l.isActive = true')
            ->setParameter('partner', $partner)
            ->orderBy('l.name', 'ASC');

        if ($type !== null) {
            $qb->andWhere('l.type = :type')->setParameter('type', $type);
        }

        return $qb->getQuery()->getResult();
    }

    public function save(MonitoredLink $link, bool $flush = true): void
    {
        $this->getEntityManager()->persist($link);
        if ($flush) $this->getEntityManager()->flush();
    }
}
