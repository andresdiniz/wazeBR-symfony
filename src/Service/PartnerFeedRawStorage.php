<?php

namespace App\Service;

final class PartnerFeedRawStorage
{
    public function __construct(
        private readonly string $projectDir,
        private readonly bool $enabled,
    ) {
    }

    public function save(int|string $partnerId, string $url, string $body): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $directory = sprintf(
            '%s/var/partner-feeds/raw/partner-%s',
            $this->projectDir,
            preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $partnerId)
        );

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Não foi possível criar o diretório %s.', $directory));
        }

        $filename = sprintf(
            '%s/%s-%s.json',
            $directory,
            (new \DateTimeImmutable())->format('Ymd_His_u'),
            substr(hash('sha256', $url . $body), 0, 16)
        );

        $json = json_decode($body, true);
        $content = json_last_error() === JSON_ERROR_NONE
            ? json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
            : $body;

        if (file_put_contents($filename, $content, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Não foi possível salvar o feed bruto em %s.', $filename));
        }

        return $filename;
    }
}
