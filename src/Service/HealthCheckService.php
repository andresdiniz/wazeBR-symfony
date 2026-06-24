<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class HealthCheckService
{
    public function __construct(
        private readonly Connection      $connection,
        private readonly LoggerInterface $logger,
    ) {}

    public function check(): array
    {
        return [
            'database'   => $this->checkDatabase(),
            'disk'       => $this->checkDisk(),
            'memory'     => $this->checkMemory(),
            'checked_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    private function checkDatabase(): array
    {
        try {
            $this->connection->executeQuery('SELECT 1');
            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            $this->logger->error('DB health check failed', ['error' => $e->getMessage()]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function checkDisk(): array
    {
        $free  = disk_free_space('/');
        $total = disk_total_space('/');
        $used  = $total - $free;
        return [
            'status'   => ($used / $total) < 0.9 ? 'ok' : 'warning',
            'used_pct' => round(($used / $total) * 100, 1),
            'free'     => $this->formatBytes((int) $free),
        ];
    }

    private function checkMemory(): array
    {
        return [
            'status'  => 'ok',
            'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ];
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
