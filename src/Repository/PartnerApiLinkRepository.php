<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PartnerApiLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
        return $this->createQueryBuilder('pal')
            ->where('pal.type = :type')
            ->setParameter('type', $type)
            ->orderBy('pal.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
