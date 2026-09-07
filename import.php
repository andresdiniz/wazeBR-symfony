<?php
/**
 * wazeBR - Import Data Model
 * 
 * This file serves as a reference model for data import operations.
 * It demonstrates the structure for importing traffic, alerts, and monitoring data.
 * 
 * Usage: This is a reference model. In Symfony, use Commands or Services for imports.
 */

// Database configuration
$dbConfig = [
    'host' => getenv('DATABASE_HOST') ?: 'localhost',
    'port' => getenv('DATABASE_PORT') ?: '3306',
    'database' => getenv('DATABASE_NAME') ?: 'wazebr',
    'username' => getenv('DATABASE_USER') ?: 'root',
    'password' => getenv('DATABASE_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
];

// API configuration
$apiConfig = [
    'waze_base_url' => getenv('WAZE_API_URL') ?: 'https://api.waze.com',
    'api_key' => getenv('WAZE_API_KEY') ?: '',
    'timeout' => 30,
    'retry_attempts' => 3,
];

/**
 * Import traffic data from external API
 * 
 * @param array $config Database configuration
 * @param array $apiConfig API configuration
 * @return array Import results
 */
function importTrafficData($dbConfig, $apiConfig) {
    $results = [
        'success' => 0,
        'failed' => 0,
        'errors' => [],
        'timestamp' => date('Y-m-d H:i:s'),
    ];
    
    try {
        // Connect to database
        $pdo = new PDO(
            "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}",
            $dbConfig['username'],
            $dbConfig['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        
        // Fetch data from API
        $apiUrl = $apiConfig['waze_base_url'] . '/traffic/live';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $apiConfig['timeout'],
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiConfig['api_key'],
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("API request failed with HTTP code: {$httpCode}");
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['alerts']) || !is_array($data['alerts'])) {
            throw new Exception("Invalid API response format");
        }
        
        // Prepare insert statement
        $stmt = $pdo->prepare("
            INSERT INTO traffic_alerts (
                uuid, type, subtype, location, latitude, longitude, 
                reported_by, reliability, confidence, timestamp, created_at
            ) VALUES (
                :uuid, :type, :subtype, :location, :latitude, :longitude,
                :reported_by, :reliability, :confidence, :timestamp, NOW()
            ) ON DUPLICATE KEY UPDATE
                type = VALUES(type),
                subtype = VALUES(subtype),
                location = VALUES(location),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                reported_by = VALUES(reported_by),
                reliability = VALUES(reliability),
                confidence = VALUES(confidence),
                timestamp = VALUES(timestamp),
                updated_at = NOW()
        ");
        
        // Process each alert
        foreach ($data['alerts'] as $alert) {
            try {
                $stmt->execute([
                    ':uuid' => $alert['uuid'] ?? null,
                    ':type' => $alert['type'] ?? null,
                    ':subtype' => $alert['subtype'] ?? null,
                    ':location' => $alert['location'] ?? null,
                    ':latitude' => $alert['latitude'] ?? null,
                    ':longitude' => $alert['longitude'] ?? null,
                    ':reported_by' => $alert['reported_by'] ?? null,
                    ':reliability' => $alert['reliability'] ?? 0,
                    ':confidence' => $alert['confidence'] ?? 0,
                    ':timestamp' => $alert['timestamp'] ?? date('Y-m-d H:i:s'),
                ]);
                
                $results['success']++;
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'uuid' => $alert['uuid'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
            }
        }
        
    } catch (Exception $e) {
        $results['errors'][] = [
            'type' => 'critical',
            'error' => $e->getMessage(),
        ];
    }
    
    return $results;
}

/**
 * Import route/jam data
 * 
 * @param array $dbConfig Database configuration
 * @param array $apiConfig API configuration
 * @return array Import results
 */
function importJamData($dbConfig, $apiConfig) {
    $results = [
        'success' => 0,
        'failed' => 0,
        'errors' => [],
        'timestamp' => date('Y-m-d H:i:s'),
    ];
    
    try {
        $pdo = new PDO(
            "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}",
            $dbConfig['username'],
            $dbConfig['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        
        // Fetch jam data from API
        $apiUrl = $apiConfig['waze_base_url'] . '/jams/live';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $apiConfig['timeout'],
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiConfig['api_key'],
                'Content-Type: application/json',
            ],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("API request failed: {$httpCode}");
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['jams']) || !is_array($data['jams'])) {
            throw new Exception("Invalid jams API response");
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO traffic_jams (
                uuid, level, speed, delay, length, line,
                start_latitude, start_longitude, end_latitude, end_longitude,
                timestamp, created_at
            ) VALUES (
                :uuid, :level, :speed, :delay, :length, :line,
                :start_lat, :start_lon, :end_lat, :end_lon,
                :timestamp, NOW()
            ) ON DUPLICATE KEY UPDATE
                level = VALUES(level),
                speed = VALUES(speed),
                delay = VALUES(delay),
                length = VALUES(length),
                line = VALUES(line),
                timestamp = VALUES(timestamp),
                updated_at = NOW()
        ");
        
        foreach ($data['jams'] as $jam) {
            try {
                $stmt->execute([
                    ':uuid' => $jam['uuid'] ?? null,
                    ':level' => $jam['level'] ?? 0,
                    ':speed' => $jam['speed'] ?? 0,
                    ':delay' => $jam['delay'] ?? 0,
                    ':length' => $jam['length'] ?? 0,
                    ':line' => json_encode($jam['line'] ?? []),
                    ':start_lat' => $jam['start_latitude'] ?? null,
                    ':start_lon' => $jam['start_longitude'] ?? null,
                    ':end_lat' => $jam['end_latitude'] ?? null,
                    ':end_lon' => $jam['end_longitude'] ?? null,
                    ':timestamp' => $jam['timestamp'] ?? date('Y-m-d H:i:s'),
                ]);
                
                $results['success']++;
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'uuid' => $jam['uuid'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
            }
        }
        
    } catch (Exception $e) {
        $results['errors'][] = [
            'type' => 'critical',
            'error' => $e->getMessage(),
        ];
    }
    
    return $results;
}

/**
 * Log import results
 * 
 * @param array $results Import results
 * @param string $logFile Log file path
 */
function logImportResults($results, $logFile = 'import.log') {
    $logEntry = sprintf(
        "[%s] Import completed - Success: %d, Failed: %d, Errors: %d\n",
        $results['timestamp'],
        $results['success'],
        $results['failed'],
        count($results['errors'])
    );
    
    if (!empty($results['errors'])) {
        foreach ($results['errors'] as $error) {
            $logEntry .= sprintf("  ERROR: %s - %s\n", 
                $error['uuid'] ?? 'unknown', 
                $error['error']
            );
        }
    }
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Example usage (for CLI execution)
if (php_sapi_name() === 'cli' && isset($argv[0]) && basename($argv[0]) === 'import.php') {
    echo "Starting wazeBR data import...\n";
    
    $trafficResults = importTrafficData($dbConfig, $apiConfig);
    logImportResults($trafficResults, 'traffic_import.log');
    
    $jamResults = importJamData($dbConfig, $apiConfig);
    logImportResults($jamResults, 'jams_import.log');
    
    echo "Traffic import: {$trafficResults['success']} success, {$trafficResults['failed']} failed\n";
    echo "Jam import: {$jamResults['success']} success, {$jamResults['failed']} failed\n";
    echo "Import completed at {$trafficResults['timestamp']}\n";
}
