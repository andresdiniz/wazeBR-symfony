<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeTrafficJam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WazeTrafficJamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTrafficJam::class);
    }

    /**
     * Conta congestionamentos de um parceiro em uma data específica.
     *
     * A data é calculada pelo horário efetivo publicado pelo Waze
     * (pubMillis), no fuso horário de Brasília.
     */
    public function countByDate(
        Partner $partner,
        \DateTimeInterface $date,
    ): int {
        [$startMillis, $endMillis] = $this->pubMillisRange($date);

        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis >= :startMillis')
            ->andWhere('j.pubMillis < :endMillis')
            ->setParameter('partner', $partner)
            ->setParameter('startMillis', $startMillis)
            ->setParameter('endMillis', $endMillis)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Mantido por compatibilidade com eventuais chamadas existentes.
     *
     * Apesar do nome legado, o cálculo usa pubMillis, não createdAt.
     */
    public function countByCreatedAtDate(
        Partner $partner,
        \DateTimeInterface $date,
    ): int {
        return $this->countByDate($partner, $date);
    }

    /**
     * Retorna o intervalo [início, fim) do dia em milissegundos UTC.
     *
     * A data recebida é interpretada como uma data civil de Brasília,
     * para que 00:00–23:59 de um dia na interface corresponda ao mesmo
     * dia nos valores pubMillis do Waze.
     *
     * @return array{0: int, 1: int}
     */
    private function pubMillisRange(
        \DateTimeInterface $date,
    ): array {
        $brasilia = new \DateTimeZone('America/Sao_Paulo');

        $start = new \DateTimeImmutable(
            $date->format('Y-m-d') . ' 00:00:00',
            $brasilia,
        );

        $end = $start->modify('+1 day');

        return [
            $start->setTimezone(new \DateTimeZone('UTC'))->getTimestamp() * 1000,
            $end->setTimezone(new \DateTimeZone('UTC'))->getTimestamp() * 1000,
        ];
    }
}
