<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\PartnerCameraLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PartnerCameraLink>
 */
class PartnerCameraLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PartnerCameraLink::class);
    }

    /**
     * @return PartnerCameraLink[]
     */
    public function findActiveByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('camera')
            ->andWhere('camera.partner = :partner')
            ->andWhere('camera.isActive = :active')
            ->setParameter('partner', $partner)
            ->setParameter('active', true)
            ->orderBy('camera.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
