<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CemadenData;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CemadenData>
 */
class CemadenDataRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CemadenData::class);
    }

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.partner = :p')->setParameter('p', $partner)
            ->getQuery()->getSingleScalarResult();
    }

    public function countDistinctMunicipalities(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(DISTINCT c.municipality)')
            ->where('c.partner = :p')->setParameter('p', $partner)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Última leitura de CADA estação pluviométrica do parceiro — não o
     * histórico inteiro.
     *
     * ANTES: retornava TODAS as linhas já coletadas (uma nova a cada
     * ciclo de coleta), sem filtrar por data nem agrupar por estação.
     * Isso fazia o operador ver "51 estações CEMADEN" quando na
     * verdade eram 51 LEITURAS acumuladas ao longo do tempo, muitas
     * vezes repetindo as mesmas poucas estações. Mesma correção já
     * aplicada em CemadenHydroDataRepository::findLatestByPartner().
     *
     * Subquery correlacionada (station_code + MAX(measured_at)) em vez
     * de trazer tudo e filtrar em PHP — evita carregar o histórico
     * inteiro na memória só para descartar quase tudo depois.
     */
    public function findByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.partner = :partner')
            ->andWhere('c.measuredAt = (
                SELECT MAX(c2.measuredAt) FROM App\Entity\CemadenData c2
                WHERE c2.partner = :partner AND c2.stationCode = c.stationCode
            )')
            ->setParameter('partner', $partner)
            ->orderBy('c.accumulatedRain', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByPartnerAndLevels(Partner $partner, array $levels): array
    {
        $since = new \DateTimeImmutable('-2 hours');
        return $this->createQueryBuilder('c')
            ->where('c.partner = :p')->setParameter('p', $partner)
            ->andWhere('c.alertLevel IN (:levels)')->setParameter('levels', $levels)
            ->andWhere('c.measuredAt >= :since')->setParameter('since', $since)
            ->orderBy('c.accumulatedRain', 'DESC')
            ->getQuery()->getResult();
    }

    public function findFilteredByPartner(
        Partner $partner,
        ?string $alertLevel = null,
        ?string $state      = null,
    ): array {
        $qb = $this->createQueryBuilder('c')
            ->where('c.partner = :p')->setParameter('p', $partner)
            ->orderBy('c.accumulatedRain', 'DESC');

        if ($alertLevel) {
            $qb->andWhere('c.alertLevel = :level')->setParameter('level', strtoupper($alertLevel));
        }
        if ($state) {
            $qb->andWhere('c.state = :state')->setParameter('state', strtoupper($state));
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByPartner(int $id, Partner $partner): ?CemadenData
    {
        return $this->createQueryBuilder('c')
            ->where('c.id = :id')->setParameter('id', $id)
            ->andWhere('c.partner = :p')->setParameter('p', $partner)
            ->getQuery()->getOneOrNullResult();
    }

    public function sumRainLastHourByPartner(Partner $partner): ?float
    {
        $since = new \DateTimeImmutable('-1 hour');

        $val = $this->createQueryBuilder('c')
            ->select('SUM(c.accumulatedRain)')
            ->where('c.partner = :p')->setParameter('p', $partner)
            ->andWhere('c.measuredAt >= :since')->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();

        return $val !== null ? round((float) $val, 1) : null;
    }

    public function latestWaterLevelByPartner(Partner $partner): ?float
    {
        $result = $this->createQueryBuilder('c')
            ->select('c.waterLevel')
            ->where('c.partner = :p')->setParameter('p', $partner)
            ->andWhere('c.waterLevel IS NOT NULL')
            ->orderBy('c.measuredAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        return $result ? (float) $result['waterLevel'] : null;
    }

    public function save(CemadenData $data, bool $flush = true): void
    {
        $this->getEntityManager()->persist($data);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * getEntityManager() é protected na classe base do Doctrine — esta
     * sobrescrita só amplia a visibilidade pra public, porque
     * NotificationDispatchCommand chama de fora da classe (isso é
     * permitido em PHP: subclasse pode ampliar visibilidade, nunca
     * restringir). Sem isso, a chamada externa dá "Call to protected
     * method" fatal error.
     */
    public function getEntityManager(): \Doctrine\ORM\EntityManagerInterface
    {
        return parent::getEntityManager();
    }

    /**
     * ATENÇÃO — SEM FILTRO DE PARCEIRO. Usado por ApiController::cemaden(),
     * o mesmo endpoint legado sem TenantContext citado em
     * WazeAlertRepository::findFiltered(). Retorna estações com alerta
     * ativo (qualquer nível diferente de vazio/NO_ALERT) de TODOS os
     * parceiros. Mesma recomendação: revisar antes de expor em produção.
     *
     * @return CemadenData[]
     */
    public function findActiveAlerts(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.alertLevel IS NOT NULL')
            ->andWhere('c.alertLevel != :noAlert')
            ->setParameter('noAlert', 'NO_ALERT')
            ->orderBy('c.measuredAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
