<?php
// src/Controller/PartnerRegistrationController.php - NOVO CONTROLLER

namespace App\Controller\Admin;

use App\Entity\Partner;
use App\Entity\User;
use App\Form\PartnerRegistrationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/partner/register')]
class PartnerRegistrationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {}

    #[Route('', name: 'partner_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        $form = $this->createForm(PartnerRegistrationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            // Verificar se email do parceiro já existe
            $existingPartner = $this->entityManager->getRepository(Partner::class)
                ->findOneBy(['email' => $data['partnerEmail']]);

            if ($existingPartner) {
                $this->addFlash('error', 'Já existe um parceiro cadastrado com este email.');
                return $this->redirectToRoute('partner_register');
            }

            // Verificar se email do usuário já existe
            $existingUser = $this->entityManager->getRepository(User::class)
                ->findOneBy(['email' => $data['userEmail']]);

            if ($existingUser) {
                $this->addFlash('error', 'Já existe um usuário cadastrado com este email.');
                return $this->redirectToRoute('partner_register');
            }

            // Criar Parceiro
            $partner = new Partner();
            $partner->setName($data['partnerName']);
            $partner->setEmail($data['partnerEmail']);
            $partner->setPhone($data['partnerPhone'] ?? null);
            $partner->setActive(true);

            $this->entityManager->persist($partner);
            $this->entityManager->flush();

            // Criar Usuário Admin
            $user = new User();
            $user->setEmail($data['userEmail']);
            $user->setFullName($data['userFullName'] ?? null);
            $user->setRoles(['ROLE_PARTNER_ADMIN']);
            $user->setPartner($partner);
            $user->setActive(true);

            $hashedPassword = $this->passwordHasher->hashPassword($user, $data['plainPassword']);
            $user->setPassword($hashedPassword);

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $this->addFlash('success', 'Cadastro realizado com sucesso! Você já pode fazer login.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('partner_registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }
}
