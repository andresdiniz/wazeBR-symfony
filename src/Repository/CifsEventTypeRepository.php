<?php

namespace App\Repository;

use App\Entity\CifsEventType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CifsEventType>
 */
class CifsEventTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CifsEventType::class);
    }

    /**
     * Retorna todos os tipos e subtipos agrupados para um locale.
     * Fallback para 'en' se o locale solicitado não existir.
     *
     * @return array<string, array{label: string, subtypes: array<string, string>}>
     */
    public function getGroupedByType(string $locale = 'pt'): array
    {
        $rows = $this->createQueryBuilder('t')
            ->where('t.locale = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('t.type')
            ->addOrderBy('t.subtype')
            ->getQuery()
            ->getResult();

        // Fallback para 'en' caso não haja registros no locale pedido
        if (empty($rows)) {
            $rows = $this->createQueryBuilder('t')
                ->where('t.locale = :locale')
                ->setParameter('locale', 'en')
                ->orderBy('t.type')
                ->addOrderBy('t.subtype')
                ->getQuery()
                ->getResult();
        }

        $grouped = [];
        /** @var CifsEventType $row */
        foreach ($rows as $row) {
            $type = $row->getType();
            if (!isset($grouped[$type])) {
                $grouped[$type] = ['label' => '', 'subtypes' => []];
            }
            if ($row->getSubtype() === null) {
                $grouped[$type]['label'] = $row->getLabel();
            } else {
                $grouped[$type]['subtypes'][$row->getSubtype()] = $row->getLabel();
            }
        }

        return $grouped;
    }

    /**
     * Retorna mapa simples [type => label] para um locale (útil em selects).
     */
    public function getTypesMap(string $locale = 'pt'): array
    {
        $rows = $this->createQueryBuilder('t')
            ->where('t.locale = :locale AND t.subtype IS NULL')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->getType()] = $row->getLabel();
        }
        return $map;
    }
}
