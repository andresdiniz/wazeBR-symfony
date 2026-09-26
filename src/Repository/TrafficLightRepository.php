<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\TrafficLight;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class TrafficLightRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrafficLight::class);
    }

    /** @return TrafficLight[] */
    public function findActive(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return TrafficLight[] */
    public function findByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByPartnerAndCode(Partner $partner, string $code): ?TrafficLight
    {
        return $this->findOneBy(['partner' => $partner, 'code' => $code]);
    }

    /** @return TrafficLight[] */
    public function findWithErrors(int $limit = 50): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.lastReadStatus = :status')
            ->setParameter('status', TrafficLight::STATUS_ERROR)
            ->orderBy('t.lastReadAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
