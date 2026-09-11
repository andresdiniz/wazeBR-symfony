<?php

namespace App\Controller;

use App\Repository\NotificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HeaderController extends AbstractController
{
    #[Route('/_partial/header', name: 'partial_header')]
    public function index(NotificationRepository $notificationRepository): Response
    {
        $notificationCount = 0;

        if ($this->getUser()) {
            $partner = $this->getUser()->getPartner();
            $notificationCount = $notificationRepository->countUnreadByPartner(
                $partner->getId()
            );
        }

        return $this->render('partials/header.html.twig', [
            'notification_count' => $notificationCount,
        ]);
    }
}
