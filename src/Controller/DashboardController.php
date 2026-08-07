        // Recent jams (u\u00faltimos 10 COM LINE) - SQL direto
        $recentJamsRaw = $conn->fetchAllAssociative(<<<'SQL'
            SELECT
                j.id,
                j.level,
                j.city,
                j.street,
                j.pub_millis,
                j.line,
                j.segments
            FROM waze_traffic_jams j
            WHERE j.line IS NOT NULL
              AND j.line != ''
              AND j.line != '[]'
            ORDER BY j.pub_millis DESC
            LIMIT 10
        SQL, []);

        // DEBUG: ver o line bruto
        if (!empty($recentJamsRaw)) {
            error_log('Line bruto do jam 0: ' . substr($recentJamsRaw[0]['line'], 0, 200));
        }

        $recentJams = array_map(function ($r) {
            $lineStr = $r['line'];
            $segmentsStr = $r['segments'];
            $coords = [];

            // Tentar line primeiro
            if (is_string($lineStr) && strlen($lineStr) > 2) {
                $decoded = json_decode($lineStr, true);
                if (is_array($decoded) && !empty($decoded)) {
                    // Formato: [{x:lon, y:lat}, ...]
                    if (isset($decoded[0]['x']) && isset($decoded[0]['y'])) {
                        $coords = $decoded;
                    } elseif (is_array($decoded[0]) && count($decoded[0]) === 2) {
                        // Formato: [[lon,lat], ...]
                        $coords = $decoded;
                    }
                }
            }

            // Fallback: tentar segments
            if (empty($coords) && is_string($segmentsStr) && strlen($segmentsStr) > 2) {
                $decodedSegments = json_decode($segmentsStr, true);
                if (is_array($decodedSegments) && !empty($decodedSegments)) {
                    foreach ($decodedSegments as $seg) {
                        if (isset($seg['x']) && isset($seg['y'])) {
                            $coords[] = ['x' => $seg['x'], 'y' => $seg['y']];
                        }
                    }
                }
            }

            return [
                'id' => (int)$r['id'],
                'level' => (int)$r['level'],
                'city' => $r['city'],
                'street' => $r['street'],
                'pubMillis' => (int)$r['pub_millis'],
                'line' => $coords,
            ];
        }, $recentJamsRaw);
