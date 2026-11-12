<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

/** Substitui apenas a leitura GET das duas listagens; os POSTs continuam nos controllers existentes. */
final class AdminTableSubscriber implements EventSubscriberInterface
{
    private const ROLES = [
        'ROLE_SUPER_ADMIN' => 'Superadministrador', 'ROLE_ADMIN' => 'Administrador global',
        'ROLE_ACCOUNT_ADMIN' => 'Administrador de contas', 'ROLE_PARTNER_ADMIN' => 'Administrador de parceiro',
        'ROLE_OPERATOR' => 'Operador', 'ROLE_FIELD_AGENT' => 'Agente de via', 'ROLE_VIEWER' => 'Visualizador',
    ];

    public function __construct(private readonly Connection $connection, private readonly Security $security, private readonly Environment $twig)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['onController', -128]];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getRequest()->isMethod('GET')) return;
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if (!in_array($route, ['admin_partner_index', 'admin_users_index'], true)) return;
        $event->setController(fn (): Response => $this->listing($request, $route === 'admin_partner_index'));
    }

    private function listing(Request $request, bool $partners): Response
    {
        $actor = $this->security->getUser();
        if (!$actor instanceof User || !$this->security->isGranted('IS_AUTHENTICATED_FULLY')) throw new AccessDeniedException();
        $persisted = $this->connection->fetchAssociative('SELECT partner_id, roles FROM `user` WHERE id = :id', ['id' => $actor->getId()]);
        if (!$persisted) throw new AccessDeniedException();
        $actorRoles = json_decode((string) $persisted['roles'], true, 512, JSON_THROW_ON_ERROR);
        $actorPartner = $persisted['partner_id'] !== null ? (int) $persisted['partner_id'] : null;
        $global = $actorPartner === null && array_intersect($actorRoles, ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']) !== [];
        $super = in_array('ROLE_SUPER_ADMIN', $actorRoles, true);
        $partnerAdmin = !$global && $this->security->isGranted('ROLE_PARTNER_ADMIN');
        if ($partners && (!$global || !$this->security->isGranted('ROLE_ADMIN'))) throw new AccessDeniedException();
        if (!$partners && !$this->security->isGranted('ROLE_ACCOUNT_ADMIN') && !$partnerAdmin) throw new AccessDeniedException();
        if ($partnerAdmin && $actorPartner === null) throw new AccessDeniedException();

        $limit = $request->query->getInt('per_page', 25);
        if (!in_array($limit, [10, 25, 50, 100], true)) $limit = 25;
        $filters = [
            'q' => mb_substr(trim((string) $request->query->get('q', '')), 0, 200),
            'city' => mb_substr(trim((string) $request->query->get('city', '')), 0, 200),
            'status' => (string) $request->query->get('status', 'all'),
            'role' => (string) $request->query->get('role', ''),
            'partner' => max(0, $request->query->getInt('partner', 0)),
            'sort' => (string) $request->query->get('sort', 'name'),
            'dir' => strtoupper((string) $request->query->get('dir', 'ASC')),
            'per_page' => $limit,
        ];
        if (!in_array($filters['status'], ['all', 'active', 'inactive'], true)) $filters['status'] = 'all';
        if (!isset(self::ROLES[$filters['role']])) $filters['role'] = '';
        if (!in_array($filters['dir'], ['ASC', 'DESC'], true)) $filters['dir'] = 'ASC';
        if ($partners) { $filters['role'] = ''; $filters['partner'] = 0; }
        if (!$partners && $partnerAdmin) $filters['partner'] = $actorPartner;
        $sorts = $partners ? ['name' => 't.name', 'code' => 't.code', 'city' => 't.city', 'status' => 't.is_active'] : ['name' => 't.name', 'email' => 't.email', 'created' => 't.created_at', 'status' => 't.is_active'];
        if (!isset($sorts[$filters['sort']])) $filters['sort'] = 'name';
        $from = $partners ? 'partner t' : '`user` t LEFT JOIN partner p ON p.id = t.partner_id';
        $where = ['1=1'];
        $params = [];
        if (!$partners && !$global && !$partnerAdmin) {
            $where[] = '(t.id = :actor OR (JSON_CONTAINS(t.roles, :super_role) = 0 AND JSON_CONTAINS(t.roles, :admin_role) = 0 AND JSON_CONTAINS(t.roles, :account_role) = 0))';
            $params += ['actor' => $actor->getId(), 'super_role' => json_encode('ROLE_SUPER_ADMIN'), 'admin_role' => json_encode('ROLE_ADMIN'), 'account_role' => json_encode('ROLE_ACCOUNT_ADMIN')];
        }
        if (!$partners && $filters['partner'] > 0) {
            $where[] = 't.partner_id = :partner'; $params['partner'] = $filters['partner'];
        }
        if ($filters['status'] !== 'all') {
            $where[] = 't.is_active = :active'; $params['active'] = $filters['status'] === 'active' ? 1 : 0;
        }
        if (!$partners && $filters['role'] !== '') {
            $where[] = 'JSON_CONTAINS(t.roles, :role) = 1'; $params['role'] = json_encode($filters['role']);
        }
        if ($filters['city'] !== '') {
            $where[] = ($partners ? 't.city' : 'p.city') . ' = :city'; $params['city'] = $filters['city'];
        }
        if ($filters['q'] !== '') {
            $columns = $partners ? ['t.name', 't.code', 't.city', 't.state'] : ['t.name', 't.email', 't.phone', 'p.name'];
            $where[] = '(' . implode(' OR ', array_map(static fn (string $column): string => $column . " LIKE :q ESCAPE '!'", $columns)) . ')';
            $params['q'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']) . '%';
        }
        $condition = implode(' AND ', $where);
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $condition, $params);
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));
        $offset = ($page - 1) * $limit;
        $columns = $partners
            ? 't.id, t.name, t.code, t.city, t.state, t.is_active, t.fetch_frequency, t.fetch_frequency_unit, (SELECT COUNT(*) FROM `user` u WHERE u.partner_id = t.id) AS users_count'
            : 't.id, t.name, t.email, t.phone, t.roles, t.partner_id, t.is_active, t.created_at, p.name AS partner_name';
        $sql = 'SELECT ' . $columns . ' FROM ' . $from . ' WHERE ' . $condition . ' ORDER BY ' . $sorts[$filters['sort']] . ' ' . $filters['dir'] . ', t.id ASC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $rows = $this->connection->fetchAllAssociative($sql, $params);
        foreach ($rows as &$row) {
            if ($partners) continue;
            $row['roles'] = json_decode((string) $row['roles'], true, 512, JSON_THROW_ON_ERROR);
            if ($row['roles'] === []) $row['roles'] = ['ROLE_VIEWER'];
            $own = (int) $row['id'] === $actor->getId();
            $row['can_edit'] = $own || $partnerAdmin || ($global
                ? ($super || !in_array('ROLE_SUPER_ADMIN', $row['roles'], true))
                : array_intersect($row['roles'], ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN', 'ROLE_ACCOUNT_ADMIN', 'ROLE_PARTNER_ADMIN', 'ROLE_FIELD_AGENT']) === []);
        }
        unset($row);
        $html = $this->twig->render('admin/paged_table.html.twig', [
            'partners_table' => $partners, 'rows' => $rows, 'filters' => $filters, 'sorts' => array_keys($sorts),
            'role_labels' => self::ROLES, 'partner_locked' => $partnerAdmin, 'page' => $page, 'pages' => $pages,
            'total' => $total, 'first' => $total ? $offset + 1 : 0, 'last' => min($offset + $limit, $total),
            'list_route' => $partners ? 'admin_partner_index' : 'admin_users_index',
            'new_route' => $partners ? 'admin_partner_new' : 'admin_users_new',
        ]);
        return new Response($html, 200, ['Cache-Control' => 'private, no-store']);
    }
}
