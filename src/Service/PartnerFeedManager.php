<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gere a pasta pública de feed de cada parceiro:
 *
 *   public/feed/{code}/feed.json
 *
 * Contrato:
 *   - ensure($code)          → garante pasta + feed.json (cria se faltar)
 *   - rename($old, $new)     → renomeia com segurança; nunca apaga a si mesma
 *   - delete($code)          → apaga a pasta inteira
 *
 * Regras rígidas:
 *   - $code normalizado para lowercase (evita problemas de case no Windows)
 *   - Toda pasta é filha DIRETA de public/feed
 *   - Comparação de paths via realpath() — nunca remove a origem por engano
 *   - Sempre verifica o disco antes de retornar
 */
final class PartnerFeedManager
{
    private const FEED_FILENAME = 'feed.json';
    private const CODE_PATTERN  = '/^[a-z0-9_-]+$/';

    private readonly string $baseDir;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        private readonly LoggerInterface $logger,
    ) {
        // Normaliza separadores (Windows) e tira barra final
        $projectDir = rtrim(str_replace('\\', '/', $projectDir), '/');

        $this->baseDir = $projectDir . '/public/feed';

        $this->logger->info('[PartnerFeedManager] init', [
            'project_dir' => $projectDir,
            'base_dir'    => $this->baseDir,
            'base_exists' => is_dir($this->baseDir),
            'base_writable' => is_dir($this->baseDir) && is_writable($this->baseDir),
        ]);
    }

    public function publicPath(string $code): string
    {
        return sprintf('/feed/%s/%s', $this->normalizeCode($code), self::FEED_FILENAME);
    }

    public function directory(string $code): string
    {
        return $this->baseDir . '/' . $this->normalizeCode($code);
    }

    public function file(string $code): string
    {
        return $this->directory($code) . '/' . self::FEED_FILENAME;
    }

    /**
     * Garante pasta + feed.json. Idempotente.
     */
    public function ensure(string $code, bool $overwrite = false): string
    {
        $code = $this->normalizeCode($code);
        $this->assertValidCode($code);

        $dir  = $this->directory($code);
        $file = $dir . '/' . self::FEED_FILENAME;

        $this->logger->info('[PartnerFeedManager] ensure — início', [
            'code' => $code,
            'dir'  => $dir,
            'file' => $file,
        ]);

        $this->ensureBaseDir();

        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf(
                    'Falha ao criar pasta "%s". Erro: %s',
                    $dir,
                    error_get_last()['message'] ?? 'desconhecido',
                ));
            }
        }

        if ($overwrite || !is_file($file)) {
            $this->writeStub($file, $code);
        }

        if (!is_file($file)) {
            throw new \RuntimeException(sprintf(
                'A pasta "%s" existe, mas o feed.json não foi criado.',
                $dir,
            ));
        }

        $this->logger->info('[PartnerFeedManager] ensure — OK', [
            'code' => $code,
            'file' => $file,
        ]);

        return $this->publicPath($code);
    }

    /**
     * Renomeia public/feed/{old} → public/feed/{new}.
     * Nunca apaga a origem por engano (comparação via realpath).
     */
    public function rename(string $oldCode, string $newCode): string
    {
        $oldCode = $this->normalizeCode($oldCode);
        $newCode = $this->normalizeCode($newCode);

        $this->assertValidCode($oldCode);
        $this->assertValidCode($newCode);

        // Mesmo code (após lowercase) → só garante que existe
        if ($oldCode === $newCode) {
            return $this->ensure($newCode);
        }

        $oldDir = $this->directory($oldCode);
        $newDir = $this->directory($newCode);

        $this->logger->info('[PartnerFeedManager] rename — início', [
            'old_code' => $oldCode,
            'new_code' => $newCode,
            'old_dir'  => $oldDir,
            'new_dir'  => $newDir,
            'old_exists' => is_dir($oldDir),
            'new_exists' => is_dir($newDir),
        ]);

        $this->ensureBaseDir();

        // Realpath de origem — se existir, é o caminho canônico
        $oldReal = is_dir($oldDir) ? realpath($oldDir) : false;
        $newReal = is_dir($newDir) ? realpath($newDir) : false;

        // ⚠️ Se newReal === oldReal, são a mesma pasta (case-insensitive
        // no Windows). Não há nada para renomear — só garantir o json.
        if ($oldReal !== false && $oldReal === $newReal) {
            $this->logger->warning(
                '[PartnerFeedManager] rename — old e new apontam para a mesma pasta. Abortando rename.',
                ['path' => $oldReal],
            );

            // Garante que o feed.json aponta pro code novo
            $file = $oldDir . '/' . self::FEED_FILENAME;
            if (is_file($file)) {
                $this->rewritePartnerField($file, $newCode);
            } else {
                $this->writeStub($file, $newCode);
            }

            return $this->publicPath($newCode);
        }

        // ── 1. Remove destino stale (se for de fato OUTRA pasta) ──
        if (is_dir($newDir)) {
            $this->removeDir($newDir);
            $this->logger->warning(
                '[PartnerFeedManager] rename — destino stale removido',
                ['dir' => $newDir],
            );
        }

        // ── 2. Renomeia origem (ou cria destino) ──────────────────
        if (is_dir($oldDir)) {
            if (!@rename($oldDir, $newDir)) {
                $err = error_get_last()['message'] ?? 'desconhecido';

                throw new \RuntimeException(sprintf(
                    'Falha ao renomear "%s" → "%s": %s',
                    $oldDir,
                    $newDir,
                    $err,
                ));
            }
        } else {
            if (!@mkdir($newDir, 0755, true) && !is_dir($newDir)) {
                throw new \RuntimeException(sprintf(
                    'Falha ao criar "%s": %s',
                    $newDir,
                    error_get_last()['message'] ?? 'desconhecido',
                ));
            }
        }

        // ── 3. Reconcilia feed.json ───────────────────────────────
        $file = $newDir . '/' . self::FEED_FILENAME;

        if (is_file($file)) {
            $this->rewritePartnerField($file, $newCode);
        } else {
            $this->writeStub($file, $newCode);
        }

        if (!is_file($file)) {
            throw new \RuntimeException(sprintf(
                'O arquivo "%s" não foi criado após o rename.',
                $file,
            ));
        }

        $this->logger->info('[PartnerFeedManager] rename — OK', [
            'new_dir' => $newDir,
            'file'    => $file,
        ]);

        return $this->publicPath($newCode);
    }

    public function delete(string $code): bool
    {
        $code = $this->normalizeCode($code);
        $this->assertValidCode($code);

        $dir = $this->directory($code);

        if (!is_dir($dir)) {
            return false;
        }

        $this->removeDir($dir);

        $this->logger->info('[PartnerFeedManager] delete — OK', [
            'dir' => $dir,
        ]);

        return true;
    }

    // ─────────────────────────────────────────────────────────────
    // Internos
    // ─────────────────────────────────────────────────────────────

    private function normalizeCode(string $code): string
    {
        return strtolower(trim($code));
    }

    private function ensureBaseDir(): void
    {
        if (is_dir($this->baseDir)) {
            return;
        }

        if (!@mkdir($this->baseDir, 0755, true) && !is_dir($this->baseDir)) {
            throw new \RuntimeException(sprintf(
                'Falha ao criar a pasta base "%s". Erro: %s',
                $this->baseDir,
                error_get_last()['message'] ?? 'desconhecido',
            ));
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = @scandir($dir);

        if ($items === false) {
            throw new \RuntimeException(sprintf(
                'Falha ao ler o diretório "%s".',
                $dir,
            ));
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                if (!@unlink($path)) {
                    throw new \RuntimeException(sprintf(
                        'Falha ao apagar arquivo "%s".',
                        $path,
                    ));
                }
            }
        }

        if (!@rmdir($dir) && is_dir($dir)) {
            throw new \RuntimeException(sprintf(
                'Falha ao remover a pasta "%s".',
                $dir,
            ));
        }
    }

    private function assertValidCode(string $code): void
    {
        if ($code === '') {
            throw new \InvalidArgumentException(
                'Código de parceiro não pode ser vazio para operações de feed.',
            );
        }

        if (!preg_match(self::CODE_PATTERN, $code)) {
            throw new \InvalidArgumentException(sprintf(
                'Código inválido para pasta de feed: "%s". Permitido: [a-z0-9_-]+ (minúsculas).',
                $code,
            ));
        }
    }

    private function writeStub(string $file, string $code): void
    {
        $this->writeJson($file, [
            'partner'     => $code,
            'generatedAt' => $this->now(),
            'items'       => [],
        ]);
    }

    private function rewritePartnerField(string $file, string $code): void
    {
        $contents = @file_get_contents($file);

        if ($contents === false) {
            $this->writeStub($file, $code);

            return;
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            $this->logger->warning(
                '[PartnerFeedManager] feed.json corrompido — recriando stub.',
                ['file' => $file],
            );

            $this->writeStub($file, $code);

            return;
        }

        $data['partner']     = $code;
        $data['generatedAt'] = $this->now();

        $this->writeJson($file, $data);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJson(string $file, array $payload): void
    {
        $encoded = json_encode(
            $payload,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE,
        );

        if ($encoded === false) {
            throw new \RuntimeException(sprintf(
                'Falha ao serializar JSON para "%s": %s',
                $file,
                json_last_error_msg(),
            ));
        }

        $bytes = @file_put_contents($file, $encoded);

        if ($bytes === false) {
            throw new \RuntimeException(sprintf(
                'Falha ao escrever "%s": %s',
                $file,
                error_get_last()['message'] ?? 'desconhecido',
            ));
        }
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format(\DateTimeInterface::ATOM);
    }
}
