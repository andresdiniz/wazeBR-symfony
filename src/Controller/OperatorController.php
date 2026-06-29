<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/operador', name: 'operator_')]
#[IsGranted('ROLE_USER')]
class OperatorController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    #[Route('', name: 'live')]
    public function live(): Response
    {
        $partner = $this->tenantContext->requirePartner();

        return $this->render('operator/live.html.twig', [
            'partner'     => $partner,
            'partnerName' => $partner->getName(),
        ]);
    }
}
