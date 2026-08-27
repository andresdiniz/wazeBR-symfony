<?php

namespace App\Repository;

use App\Entity\WazeRoute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeRoute>
 */
class WazeRouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, WazeRoute::class);
    }

    /**
     * @return WazeRoute[]
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('wr')
            ->where('wr.isActive = :isActive')
            ->setParameter('isActive', true)
            ->orderBy('wr.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveByLink(string $link): ?WazeRoute
{
    return $this->createQueryBuilder('wr')
        ->where('wr.isActive = :isActive')
        ->andWhere('wr.link = :link')
        ->setParameter('isActive', true)
        ->setParameter('link', $link)
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult();
}

    /**
     * Alias de findActive() — usado por WazeCollectRoutesCommand quando
     * nenhum --partner é informado (roda para todos os parceiros).
     *
     * NOTA: findAllActive() era chamado sem existir aqui, causando
     * BadMethodCallException em toda execução de app:waze:collect-routes
     * sem --partner (mesmo padrão de bug já corrigido em outros repositories).
     *
     * @return WazeRoute[]
     */
    public function findAllActive(): array
    {
        return $this->findActive();
    }

    /**
     * Rotas ativas de um parceiro específico, filtrando por slug.
     *
     * NOTA: era chamado sem existir aqui, causando BadMethodCallException
     * em toda execução de app:waze:collect-routes --partner=<slug>.
     *
     * @return WazeRoute[]
     */
    public function findActiveByPartnerSlug(string $slug): array
    {
        return $this->createQueryBuilder('wr')
            ->innerJoin('wr.partner', 'p')
            ->where('wr.isActive = :isActive')
            ->andWhere('p.slug = :slug')
            ->setParameter('isActive', true)
            ->setParameter('slug', $slug)
            ->orderBy('wr.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
