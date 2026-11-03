<?php

namespace App\Controller;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\PasswordResetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('security/login.html.twig', [
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'last_username' => $authenticationUtils->getLastUsername(),
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET', 'POST'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by Symfony Security.');
    }

    #[Route('/password/forgot', name: 'app_password_forgot', methods: ['GET', 'POST'])]
    public function forgot(
        Request $request,
        EntityManagerInterface $entityManager,
        PasswordResetService $resetService,
        MailerInterface $mailer,
        AuditLogger $auditLogger
    ): Response {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('forgot_password', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $emailInput = strtolower(trim((string) $request->request->get('email')));
            $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $emailInput]);

            if ($user instanceof User && $user->isActive()) {
                [, $plainToken] = $resetService->issue($user);
                $resetUrl = $this->generateUrl('app_password_reset', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_URL);

                $email = (new Email())
                    ->to($user->getEmail())
                    ->subject('Réinitialisation de votre accès MCI e-Circuit')
                    ->text("Bonjour {$user->getFullName()},\n\nUne demande de réinitialisation de votre mot de passe a été initiée.\nUtilisez ce lien valable 30 minutes :\n{$resetUrl}\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message.");

                $mailer->send($email);
                $auditLogger->log(
                    event: \App\Enum\AuditAction::PasswordReset,
                    success: true,
                    user: $user,
                    request: $request,
                    context: ['stage' => 'request', 'email' => $user->getEmail()],
                    entityType: 'User',
                    entityId: (string) $user->getId()
                );
            }

            $this->addFlash('success', 'Si cette adresse est reconnue, un lien de réinitialisation vient d’être envoyé.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/forgot_password.html.twig');
    }

    #[Route('/password/reset', name: 'app_password_reset', methods: ['GET', 'POST'])]
    public function reset(
        Request $request,
        EntityManagerInterface $entityManager,
        PasswordResetService $resetService,
        AuditLogger $auditLogger
    ): Response {
        $plainToken = (string) ($request->query->get('token') ?? $request->request->get('token', ''));
        $token = $plainToken === '' ? null : $resetService->findValidToken($plainToken);

        if (!$token instanceof PasswordResetToken) {
            $this->addFlash('error', 'Ce lien de réinitialisation est invalide ou expiré.');
            return $this->redirectToRoute('app_password_forgot');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('reset_password', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $password = (string) $request->request->get('password');
            $confirmation = (string) $request->request->get('password_confirmation');

            if (strlen($password) < 8 || $password !== $confirmation) {
                $this->addFlash('error', 'Le mot de passe doit comporter au moins 8 caractères et être confirmé de manière identique.');
                return $this->render('security/reset_password.html.twig', ['token' => $plainToken]);
            }

            $user = $token->getUser();
            if ($resetService->reset($token, $plainToken, $password)) {
                $auditLogger->log(
                    event: \App\Enum\AuditAction::PasswordReset,
                    success: true,
                    user: $user,
                    request: $request,
                    context: ['stage' => 'completed', 'email' => $user->getEmail()],
                    entityType: 'User',
                    entityId: (string) $user->getId()
                );
                $this->addFlash('success', 'Votre mot de passe a été mis à jour avec succès. Vous pouvez maintenant vous connecter.');
                return $this->redirectToRoute('app_login');
            }

            $this->addFlash('error', 'Échec lors de la réinitialisation du mot de passe.');
        }

        return $this->render('security/reset_password.html.twig', ['token' => $plainToken]);
    }
}
