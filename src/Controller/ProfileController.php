<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile')]
#[IsGranted('ROLE_USER')]
final class ProfileController extends AbstractController
{
    #[Route('', name: 'app_profile', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $hasher,
        AuditLogger $auditLogger
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('profile_change_password', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $currentPassword = (string) $request->request->get('current_password');
            $newPassword = (string) $request->request->get('new_password');
            $confirmPassword = (string) $request->request->get('new_password_confirmation');

            if (!$hasher->isPasswordValid($user, $currentPassword)) {
                $auditLogger->log(
                    event: \App\Enum\AuditAction::PasswordChanged,
                    success: false,
                    user: $user,
                    request: $request,
                    context: ['reason' => 'invalid_current_password'],
                    entityType: 'User',
                    entityId: (string) $user->getId(),
                    result: \App\Enum\AuditResult::Failure
                );
                $this->addFlash('error', 'Le mot de passe actuel saisi est incorrect.');
                return $this->redirectToRoute('app_profile');
            }

            if (strlen($newPassword) < 8) {
                $this->addFlash('error', 'Le nouveau mot de passe doit comporter au moins 8 caractères.');
                return $this->redirectToRoute('app_profile');
            }

            if ($newPassword !== $confirmPassword) {
                $this->addFlash('error', 'Le nouveau mot de passe et sa confirmation ne correspondent pas.');
                return $this->redirectToRoute('app_profile');
            }

            $user->setPassword($hasher->hashPassword($user, $newPassword));
            $entityManager->flush();

            $auditLogger->log(
                event: \App\Enum\AuditAction::PasswordChanged,
                success: true,
                user: $user,
                request: $request,
                context: ['email' => $user->getEmail()],
                entityType: 'User',
                entityId: (string) $user->getId()
            );
            $this->addFlash('success', 'Votre mot de passe a été modifié avec succès.');

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/index.html.twig', [
            'user' => $user,
        ]);
    }
}
