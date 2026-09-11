<?php

namespace App\Controller;

use App\Entity\Partner;
use App\Repository\NotificationRepository;
use App\Repository\PartnerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class HeaderController extends AbstractController
{
    public function __construct(
        private NotificationRepository $notificationRepository,
        private PartnerRepository $partnerRepository
    ) {}

    #[Route('/_header', name: '_header', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        $notificationCount = 0;

        if ($user) {
            $partner = $this->partnerRepository->find($user->getUserIdentifier());
            if ($partner instanceof Partner) {
                $notificationCount = $this->notificationRepository->countUnreadByPartner($partner);
            }
        }

        return $this->render('partials/header.html.twig', [
            'notification_count' => $notificationCount,
        ]);
    }
}
