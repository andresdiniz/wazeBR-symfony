<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\PartnerApiLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PartnerApiLink>
 */
class PartnerApiLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PartnerApiLink::class);
    }

    /**
     * @return PartnerApiLink[]
     */
    public function findAllByType(string $type): array
    {
        return $this->createQueryBuilder('link')
            ->innerJoin('link.partner', 'partner')
            ->addSelect('partner')
            ->andWhere('UPPER(link.type) = :type')
            ->andWhere('link.active = :active')
            ->andWhere('partner.isActive = :partnerActive')
            ->setParameter('type', mb_strtoupper(trim($type)))
            ->setParameter('active', true)
            ->setParameter('partnerActive', true)
            ->orderBy('partner.id', 'ASC')
            ->addOrderBy('link.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PartnerApiLink[]
     */
    public function findAllActiveByPartner(Partner $partner): array
    {
        if (!$partner->isActive()) {
            return [];
        }

        return $this->createQueryBuilder('link')
            ->innerJoin('link.partner', 'partner')
            ->andWhere('link.partner = :partner')
            ->andWhere('link.active = :active')
            ->andWhere('partner.isActive = :partnerActive')
            ->setParameter('partner', $partner)
            ->setParameter('active', true)
            ->setParameter('partnerActive', true)
            ->orderBy('link.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
