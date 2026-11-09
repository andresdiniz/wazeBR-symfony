<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\JamSpeedHistoryReader;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class JamSpeedHistoryController extends AbstractController
{
    #[Route('/jams/{id}/velocidade', name: 'jam_speed_history', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, Connection $db, JamSpeedHistoryReader $reader): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $user = $this->getUser();
        if (!$user instanceof User) throw $this->createAccessDeniedException();
        $params = ['id' => $id];
        $scope = '';
        if (!$user->isGlobalAdmin()) {
            $partner = $user->getPartner();
            if ($partner === null) throw $this->createAccessDeniedException('Usuário sem parceiro.');
            $params['pid'] = $partner->getId();
            $scope = ' AND partner_id = :pid';
        }
        $jam = $db->fetchAssociative('SELECT id, partner_id, street, city FROM waze_jams WHERE id = :id'.$scope.' LIMIT 1', $params);
        if ($jam === false) throw $this->createNotFoundException('Congestionamento não encontrado.');
        $history = null;
        $error = null;
        try {
            $history = $reader->findLatest((int) $jam['partner_id'], $id);
        } catch (\RuntimeException | \InvalidArgumentException) {
            $error = 'Não foi possível carregar o histórico. Tente novamente após a próxima atualização.';
        }
        $response = $this->render('jam/speed_history.html.twig', ['jam' => $jam, 'history' => $history, 'error' => $error]);
        $response->setStatusCode($error === null ? 200 : 503);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
