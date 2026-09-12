<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeTvtRoute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeTvtRoute>
 */
class WazeTvtRouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTvtRoute::class);
    }

    /**
     * Retorna todas as rotas TVT de um partner, com subroutes pré-carregadas.
     *
     * @return WazeTvtRoute[]
     */
    public function findByPartnerWithSubRoutes(Partner $partner): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('sr')
            ->leftJoin('r.subRoutes', 'sr')
            ->where('r.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retorna rotas TVT de todos os partners, agrupando por partner.
     * Eager-load de partner e subRoutes para uso nos commands.
     *
     * @return WazeTvtRoute[]
     */
    public function findAllWithPartnerAndSubRoutes(): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('p', 'sr')
            ->join('r.partner', 'p')
            ->leftJoin('r.subRoutes', 'sr')
            ->orderBy('p.id', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Busca uma rota pelo par (partner, routeId).
     * Retorna null se ainda não existe — usado no upsert do command TVT.
     */
    public function findOneByPartnerAndRouteId(Partner $partner, string $routeId): ?WazeTvtRoute
    {
        return $this->createQueryBuilder('r')
            ->where('r.partner = :partner')
            ->andWhere('r.routeId = :routeId')
            ->setParameter('partner', $partner)
            ->setParameter('routeId', $routeId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
