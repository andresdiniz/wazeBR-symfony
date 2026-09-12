<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\PartnerApiLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PartnerApiLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PartnerApiLink::class);
    }

    /** @return list<PartnerApiLink> */
    public function findActiveByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('link')
            ->andWhere('link.partner = :partner')
            ->andWhere('link.active = :active')
            ->setParameter('partner', $partner)
            ->setParameter('active', true)
            ->orderBy('link.type', 'ASC')
            ->addOrderBy('link.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<PartnerApiLink> */
    public function findActiveByPartnerAndType(Partner $partner, string $type): array
    {
        return $this->createQueryBuilder('link')
            ->andWhere('link.partner = :partner')
            ->andWhere('link.type = :type')
            ->andWhere('link.active = :active')
            ->setParameter('partner', $partner)
            ->setParameter('type', $type)
            ->setParameter('active', true)
            ->orderBy('link.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
