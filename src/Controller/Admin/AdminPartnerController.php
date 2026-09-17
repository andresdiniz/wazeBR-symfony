<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Partner;
use App\Repository\PartnerRepository;
use App\Service\PartnerFeedManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/partners', name: 'admin_partner_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminPartnerController extends AbstractController
{
    private const CASCADE_DELETE_ENTITIES = [
        \App\Entity\WazeTvtUserOnJam::class,
        \App\Entity\WazeTvtRouteSnapshot::class,
        \App\Entity\WazeTvtIrregularity::class,
        \App\Entity\WazeTvtSubRoute::class,
        \App\Entity\WazeTvtRoute::class,
        \App\Entity\WazeAlert::class,
        \App\Entity\WazeJam::class,
        \App\Entity\WeatherLocation::class,
        \App\Entity\PartnerApiLink::class,
        \App\Entity\User::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerRepository $partners,
        private readonly PartnerFeedManager $feedManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────
    // LISTAGEM
    // ─────────────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/partner/index.html.twig', [
            'partners' => $this->partners->findBy([], ['name' => 'ASC']),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // CRIAR
    // ─────────────────────────────────────────────────────────────────

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleCreate($request);
        }

        return $this->render('admin/partner/new.html.twig');
    }

    private function handleCreate(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(
            'admin_partner_new',
            (string) $request->request->get('_token'),
        )) {
            $this->addFlash('error', 'Token de segurança inválido. Recarregue a página.');

            return $this->redirectToRoute('admin_partner_new');
        }

        $data   = $this->readFormData($request);
        $errors = $this->validate($data, null);

        if ($errors !== []) {
            return $this->fail($errors, 'admin_partner_new');
        }

        // Defesa em profundidade: nunca persistir code vazio.
        if ($data['code'] === '') {
            $this->logger->error('[AdminPartner] code vazio pós-validate (create).', [
                'data' => $data,
            ]);

            return $this->fail(
                ['O código do parceiro não pode ficar vazio.'],
                'admin_partner_new',
            );
        }

        // ── Fase 1: filesystem ─────────────────────────────────────
        try {
            $feedUrl = $this->feedManager->ensure($data['code']);
        } catch (\Throwable $e) {
            $this->logger->error('[AdminPartner] falha ao criar pasta do feed.', [
                'code' => $data['code'],
                'ex'   => $e,
            ]);

            return $this->fail(
                ['Não foi possível criar a pasta do feed: ' . $e->getMessage()],
                'admin_partner_new',
            );
        }

        // ── Fase 2: banco ──────────────────────────────────────────
        $partner = new Partner();
        $this->applyData($partner, $data);
        $partner->setFeedUrl($feedUrl);
        $partner->setIsActive(true);

        try {
            $this->em->persist($partner);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('[AdminPartner] falha ao persistir parceiro.', [
                'code' => $data['code'],
                'ex'   => $e,
            ]);

            // rollback best-effort do filesystem
            try {
                $this->feedManager->delete($data['code']);
            } catch (\Throwable) {
                // ignora
            }

            $this->em->clear();

            return $this->fail(
                ['Falha ao salvar no banco: ' . $e->getMessage()],
                'admin_partner_new',
            );
        }

        $this->addFlash(
            'success',
            sprintf('Parceiro "%s" criado com sucesso.', $partner->getName()),
        );

        return $this->redirectToRoute('admin_partner_index');
    }

    // ─────────────────────────────────────────────────────────────────
    // EDITAR
    // ─────────────────────────────────────────────────────────────────

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Partner $partner, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleUpdate($partner, $request);
        }

        return $this->render('admin/partner/edit.html.twig', [
            'partner' => $partner,
        ]);
    }

    private function handleUpdate(Partner $partner, Request $request): Response
    {
        $partnerId = $partner->getId();

        if ($partnerId === null) {
            $this->addFlash('error', 'Parceiro inválido.');

            return $this->redirectToRoute('admin_partner_index');
        }

        // ── CSRF ───────────────────────────────────────────────────
        if (!$this->isCsrfTokenValid(
            'admin_partner_edit_' . $partnerId,
            (string) $request->request->get('_token'),
        )) {
            $this->addFlash(
                'error',
                'Token de segurança inválido. Recarregue a página e tente novamente.',
            );

            return $this->redirectToRoute('admin_partner_edit', ['id' => $partnerId]);
        }

        // ── Entrada + validação ────────────────────────────────────
        $data   = $this->readFormData($request);
        $errors = $this->validate($data, $partner);

        if ($errors !== []) {
            return $this->fail($errors, 'admin_partner_edit', ['id' => $partnerId]);
        }

        // oldCode pode ser null (registro legado) ou algo válido.
        // O cast (string) transforma null em "" — tratamos os dois casos.
        $rawOldCode = $partner->getCode();
        $oldCode    = $rawOldCode !== null ? strtolower(trim($rawOldCode)) : '';
        $newCode    = $data['code'];

        // Só renomeia se JÁ existia código E ele mudou.
        //   - oldCode vazio → registro legado, só ensure()
        //   - oldCode === newCode → só ensure() (idempotente)
        //   - caso contrário → rename()
        $needsRename = $oldCode !== '' && $oldCode !== $newCode;

        $this->logger->info('[AdminPartner] handleUpdate — entrada', [
            'partner_id'     => $partnerId,
            'old_code_raw'   => $rawOldCode,
            'old_code'       => $oldCode,
            'new_code'       => $newCode,
            'needs_rename'   => $needsRename,
            'legacy_no_code' => $oldCode === '',
        ]);

        // ── FASE 1: FILESYSTEM (antes do banco) ────────────────────
        $fsDone = false;

        try {
            if ($needsRename) {
                $this->feedManager->rename($oldCode, $newCode);
            } else {
                // Cobre:
                //   - registro legado sem code (só cria)
                //   - código inalterado (só garante)
                $this->feedManager->ensure($newCode);
            }

            $fsDone = true;
        } catch (\Throwable $e) {
            $this->logger->error('[AdminPartner] falha no filesystem.', [
                'partner_id' => $partnerId,
                'old_code'   => $oldCode,
                'new_code'   => $newCode,
                'ex'         => $e,
            ]);

            return $this->fail(
                ['Não foi possível preparar a pasta do feed: ' . $e->getMessage()],
                'admin_partner_edit',
                ['id' => $partnerId],
            );
        }

        // ── FASE 2: BANCO DE DADOS ─────────────────────────────────
        try {
            $this->applyData($partner, $data);
            $partner->setFeedUrl($this->feedManager->publicPath($newCode));

            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            $this->rollbackFs($needsRename, $fsDone, $newCode, $oldCode, $partnerId);
            $this->em->clear();

            $this->logger->warning('[AdminPartner] código duplicado.', [
                'partner_id' => $partnerId,
                'new_code'   => $newCode,
            ]);

            return $this->fail(
                [sprintf('Já existe outro parceiro com o código "%s".', $newCode)],
                'admin_partner_edit',
                ['id' => $partnerId],
            );
        } catch (\Throwable $e) {
            $this->rollbackFs($needsRename, $fsDone, $newCode, $oldCode, $partnerId);
            $this->em->clear();

            $this->logger->error('[AdminPartner] falha ao salvar no banco.', [
                'partner_id' => $partnerId,
                'ex'         => $e,
            ]);

            return $this->fail(
                ['Falha ao salvar no banco: ' . $e->getMessage()],
                'admin_partner_edit',
                ['id' => $partnerId],
            );
        }

        // ── Sucesso ────────────────────────────────────────────────
        $this->logger->info('[AdminPartner] handleUpdate — OK', [
            'partner_id' => $partnerId,
            'new_code'   => $newCode,
            'file'       => $this->feedManager->file($newCode),
        ]);

        $this->addFlash(
            'success',
            sprintf(
                'Parceiro "%s" atualizado. Feed em public/feed/%s/feed.json',
                $data['name'],
                $newCode,
            ),
        );

        return $this->redirectToRoute('admin_partner_index');
    }

    /**
     * Desfaz o rename se o banco falhou depois.
     * Só faz sentido quando houve rename real.
     */
    private function rollbackFs(
        bool $needsRename,
        bool $fsDone,
        string $newCode,
        string $oldCode,
        int $partnerId,
    ): void {
        if (!$needsRename || !$fsDone) {
            return;
        }

        if ($oldCode === '' || $newCode === '' || $oldCode === $newCode) {
            return;
        }

        try {
            $this->feedManager->rename($newCode, $oldCode);
        } catch (\Throwable $re) {
            $this->logger->error('[AdminPartner] rollback de FS falhou.', [
                'partner_id' => $partnerId,
                'ex'         => $re,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // TOGGLE ATIVO / INATIVO
    // ─────────────────────────────────────────────────────────────────

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(Partner $partner, Request $request): Response
    {
        $partnerId = $partner->getId();

        if ($partnerId === null) {
            $this->addFlash('error', 'Parceiro inválido.');

            return $this->redirectToRoute('admin_partner_index');
        }

        if (!$this->isCsrfTokenValid(
            'admin_partner_toggle_' . $partnerId,
            (string) $request->request->get('_token'),
        )) {
            $this->addFlash('error', 'Token de segurança inválido.');

            return $this->redirectToRoute('admin_partner_index');
        }

        $activating = !$partner->isActive();

        if ($activating) {
            $partner->activate();
        } else {
            $partner->deactivate();
        }

        foreach ($partner->getUsers() as $user) {
            $user->setActive($activating);
        }

        foreach ($partner->getApiLinks() as $link) {
            if ($activating) {
                $link->activate();
            } else {
                $link->deactivate();
            }
        }

        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('[AdminPartner] falha ao alternar status.', [
                'partner_id' => $partnerId,
                'ex'         => $e,
            ]);

            $this->em->clear();

            $this->addFlash('error', 'Não foi possível alterar o status: ' . $e->getMessage());

            return $this->redirectToRoute('admin_partner_index');
        }

        $this->addFlash(
            'success',
            sprintf(
                'Parceiro "%s" %s. Usuários e links de coleta foram %s.',
                $partner->getName(),
                $activating ? 'ativado' : 'desativado',
                $activating ? 'reativados' : 'desativados',
            ),
        );

        return $this->redirectToRoute('admin_partner_index');
    }

    // ─────────────────────────────────────────────────────────────────
    // EXCLUIR (cascata total)
    // ─────────────────────────────────────────────────────────────────

    #[Route('/{id}', name: 'delete', methods: ['POST'])]
    public function delete(Partner $partner, Request $request): Response
    {
        $partnerId = $partner->getId();

        if ($partnerId === null) {
            $this->addFlash('error', 'Parceiro inválido.');

            return $this->redirectToRoute('admin_partner_index');
        }

        if (!$this->isCsrfTokenValid(
            'admin_partner_delete_' . $partnerId,
            (string) $request->request->get('_token'),
        )) {
            $this->addFlash('error', 'Token de segurança inválido.');

            return $this->redirectToRoute('admin_partner_index');
        }

        $deleteFeed = (bool) $request->request->get('deleteFeed', false);
        $code       = $partner->getCode() !== null ? strtolower(trim((string) $partner->getCode())) : '';
        $name       = (string) $partner->getName();

        // ── Filesystem (opcional) ──────────────────────────────────
        if ($deleteFeed && $code !== '') {
            try {
                $this->feedManager->delete($code);
            } catch (\Throwable $e) {
                $this->logger->error('[AdminPartner] falha ao apagar pasta.', [
                    'partner_id' => $partnerId,
                    'code'       => $code,
                    'ex'         => $e,
                ]);

                $this->addFlash(
                    'error',
                    'Falha ao apagar a pasta do feed: ' . $e->getMessage(),
                );

                return $this->redirectToRoute('admin_partner_index');
            }
        }

        // ── Banco (transação em cascata) ───────────────────────────
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            foreach (self::CASCADE_DELETE_ENTITIES as $entityClass) {
                $this->em
                    ->createQuery(sprintf(
                        'DELETE FROM %s e WHERE e.partner = :partner',
                        $entityClass,
                    ))
                    ->setParameter('partner', $partnerId)
                    ->execute();
            }

            $this->em
                ->createQuery('DELETE FROM App\Entity\Partner p WHERE p.id = :id')
                ->setParameter('id', $partnerId)
                ->execute();

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->em->clear();

            $this->logger->error('[AdminPartner] falha ao excluir em cascata.', [
                'partner_id' => $partnerId,
                'ex'         => $e,
            ]);

            $this->addFlash(
                'error',
                'Falha ao excluir o parceiro: ' . $e->getMessage(),
            );

            return $this->redirectToRoute('admin_partner_index');
        }

        $this->addFlash(
            'success',
            sprintf(
                'Parceiro "%s" e todos os dados vinculados foram removidos%s.',
                $name,
                $deleteFeed ? ' (incluindo a pasta do feed)' : '',
            ),
        );

        return $this->redirectToRoute('admin_partner_index');
    }

    // ─────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────

    /**
     * @return array{
     *     name: string,
     *     code: string,
     *     city: string,
     *     state: string,
     *     fetchFrequency: int,
     *     fetchFrequencyUnit: string
     * }
     */
    private function readFormData(Request $request): array
    {
        $frequency = (int) $request->request->get('fetchFrequency', 5);
        $unit      = (string) $request->request->get('fetchFrequencyUnit', 'minutes');

        $unit = in_array($unit, ['minutes', 'seconds', 'hours'], true)
            ? $unit
            : 'minutes';

        return [
            'name'               => trim((string) $request->request->get('name', '')),
            // Código sempre em lowercase — vira nome da pasta.
            'code'               => strtolower(trim((string) $request->request->get('code', ''))),
            'city'               => trim((string) $request->request->get('city', '')),
            'state'              => mb_strtoupper(trim((string) $request->request->get('state', ''))),
            'fetchFrequency'     => $frequency > 0 ? $frequency : 5,
            'fetchFrequencyUnit' => $unit,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function validate(array $data, ?Partner $current): array
    {
        $errors = [];

        if ($data['name'] === '') {
            $errors[] = 'Informe o nome do parceiro.';
        } elseif (mb_strlen($data['name']) > 255) {
            $errors[] = 'O nome deve ter no máximo 255 caracteres.';
        }

        if ($data['code'] === '') {
            $errors[] = 'Informe o código do parceiro.';
        } elseif (!preg_match('/^[a-z0-9_-]+$/', $data['code'])) {
            $errors[] = 'O código deve conter apenas letras minúsculas, números, hífen ou underscore.';
        } elseif (mb_strlen($data['code']) > 50) {
            $errors[] = 'O código deve ter no máximo 50 caracteres.';
        } else {
            $existing = $this->partners->createQueryBuilder('p')
                ->where('LOWER(p.code) = :code')
                ->setParameter('code', $data['code'])
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($existing !== null && $existing->getId() !== $current?->getId()) {
                $errors[] = sprintf(
                    'Já existe um parceiro com o código "%s".',
                    $data['code'],
                );
            }
        }

        if ($data['state'] !== '' && mb_strlen($data['state']) !== 2) {
            $errors[] = 'A UF deve ter exatamente 2 caracteres.';
        }

        if ($data['city'] !== '' && mb_strlen($data['city']) > 255) {
            $errors[] = 'A cidade deve ter no máximo 255 caracteres.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyData(Partner $partner, array $data): void
    {
        $partner->setName($data['name']);
        $partner->setCode($data['code']);
        $partner->setCity($data['city'] !== '' ? $data['city'] : null);
        $partner->setState($data['state'] !== '' ? $data['state'] : null);
        $partner->setFetchFrequency($data['fetchFrequency']);
        $partner->setFetchFrequencyUnit($data['fetchFrequencyUnit']);
    }

    /**
     * @param list<string>         $errors
     * @param array<string, mixed> $params
     */
    private function fail(array $errors, string $route, array $params = []): Response
    {
        foreach ($errors as $error) {
            $this->addFlash('error', $error);
        }

        return $this->redirectToRoute($route, $params);
    }
}
