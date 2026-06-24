<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PartnerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Partner::class);
    }

    public function findByApiToken(string $token): ?Partner
    {
        return $this->findOneBy(['apiToken' => $token, 'isActive' => true]);
    }

    public function findActivePartners(): array
    {
        return $this->findBy(['isActive' => true], ['name' => 'ASC']);
    }

    public function save(Partner $partner, bool $flush = true): void
    {
        $this->getEntityManager()->persist($partner);
        if ($flush) $this->getEntityManager()->flush();
    }
}
