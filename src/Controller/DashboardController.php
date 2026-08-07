        // Recent jams (u\u00faltimos 10) - parsing de line
        $recentJamsRaw = $this->jamRepo->createQueryBuilder('j')
            ->select('j.id, j.level, j.city, j.street, j.pubMillis, j.line, j.segments')
            ->where('j.line IS NOT NULL AND j.line != :emptyString')
            ->setParameter('emptyString', '')
            ->orderBy('j.pubMillis', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getArrayResult();
