<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ActivityLogRepository;
use App\Repository\PartnerRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ACCOUNT_ADMIN')]
class AccountSettingsController extends AbstractController
{
    private const INTERVAL_OPTIONS = [5, 10, 15, 30, 60];
    private const LOGS_PER_PAGE = 50;

    public function __construct(
        private readonly PartnerRepository $partnerRepo,
        private readonly ActivityLogRepository $logRepo,
        private readonly TenantContext $tenantContext,
    ) {
    }

    private function requirePartner(): \App\Entity\Partner
    {
        return $this->tenantContext->requirePartner();
    }

    #[Route('/account/settings', name: 'account_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $partner = $this->requirePartner();
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('account_settings', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Token inválido.');

                return $this->redirectToRoute('account_settings');
            }

            $name = trim((string) $request->request->get('name', ''));
            if ($name === '') {
                $errors['name'] = 'Informe o nome da organização.';
            } elseif (mb_strlen($name) > 255) {
                $errors['name'] = 'O nome da organização deve ter no máximo 255 caracteres.';
            } else {
                $partner->setName($name);
            }

            $rawInterval = $request->request->get('refresh_interval');
            if ($rawInterval === '' || $rawInterval === null) {
                $partner->setRefreshIntervalMinutes(null);
            } else {
                $interval = filter_var($rawInterval, FILTER_VALIDATE_INT);
                if ($interval === false || !in_array($interval, self::INTERVAL_OPTIONS, true)) {
                    $errors['refresh_interval'] = 'Intervalo inválido.';
                } else {
                    $partner->setRefreshIntervalMinutes($interval);
                }
            }

            if ($errors === []) {
                $this->partnerRepo->save($partner);
                $this->addFlash('success', 'Configurações salvas.');

                return $this->redirectToRoute('account_settings');
            }
        }

        return $this->render('account/settings.html.twig', [
            'partner' => $partner,
            'intervalOptions' => self::INTERVAL_OPTIONS,
            'errors' => $errors,
        ]);
    }

    #[Route('/account/logs', name: 'account_logs', methods: ['GET'])]
    public function logs(Request $request): Response
    {
        $partner = $this->requirePartner();
        $page = max(1, $request->query->getInt('page', 1));
        $total = $this->logRepo->countErrorsByPartner($partner);
        $logs = $this->logRepo->findErrorsByPartner($partner, $page, self::LOGS_PER_PAGE);
        $totalPages = (int) ceil($total / self::LOGS_PER_PAGE);

        return $this->render('account/logs.html.twig', [
            'partner' => $partner,
            'logs' => $logs,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }
}
