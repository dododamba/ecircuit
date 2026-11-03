<?php

namespace App\Controller\Api;

use App\Entity\AccessPermission;
use App\Entity\AccessRole;
use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\PasswordResetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

#[Route('/api/auth')]
final class AuthApiController extends AbstractController
{
    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        AuditLogger $auditLogger,
        TokenStorageInterface $tokenStorage
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];
        $email = strtolower(trim((string) ($data['email'] ?? $request->request->get('email', ''))));
        $password = (string) ($data['password'] ?? $request->request->get('password', ''));

        if ($email === '' || $password === '') {
            $auditLogger->log('LOGIN_FAILURE', false, null, $request, ['reason' => 'missing_credentials']);
            return $this->json(['error' => 'Identifiants requis.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user instanceof User || !$hasher->isPasswordValid($user, $password)) {
            $auditLogger->log('LOGIN_FAILURE', false, null, $request, ['identifier' => $email]);
            return $this->json(['error' => 'Identifiants invalides.'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$user->isActive()) {
            $auditLogger->log('LOGIN_FAILURE', false, $user, $request, ['reason' => 'account_inactive']);
            return $this->json(['error' => 'Ce compte utilisateur est inactif ou verrouillé.'], Response::HTTP_FORBIDDEN);
        }

        $user->recordLogin();
        $em->flush();

        // Authenticate into Symfony Security token storage & session
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $tokenStorage->setToken($token);
        if ($request->hasSession()) {
            $request->getSession()->set('_security_main', serialize($token));
        }

        $auditLogger->log('LOGIN_SUCCESS', true, $user, $request);

        return $this->json([
            'success' => true,
            'message' => 'Connexion réussie.',
            'user' => $this->serializeUser($user),
        ]);
    }

    #[Route('/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(Request $request, TokenStorageInterface $tokenStorage, AuditLogger $auditLogger): JsonResponse
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            $auditLogger->log('LOGOUT', true, $user, $request);
        }

        $tokenStorage->setToken(null);
        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }

        return $this->json(['success' => true, 'message' => 'Déconnexion effectuée.']);
    }

    #[Route('/forgot-password', name: 'api_auth_forgot_password', methods: ['POST'])]
    public function forgotPassword(
        Request $request,
        EntityManagerInterface $em,
        PasswordResetService $resetService,
        MailerInterface $mailer,
        AuditLogger $auditLogger
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];
        $email = strtolower(trim((string) ($data['email'] ?? $request->request->get('email', ''))));

        if ($email === '') {
            return $this->json(['error' => 'Adresse email requise.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($user instanceof User && $user->isActive()) {
            [, $plainToken] = $resetService->issue($user);
            $resetUrl = $this->generateUrl('app_password_reset', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_URL);

            $mail = (new Email())
                ->to($user->getEmail())
                ->subject('Réinitialisation de votre mot de passe — MCI e-Circuit')
                ->text("Bonjour {$user->getFullName()},\n\nUn lien de réinitialisation vous a été généré :\n{$resetUrl}\n\nCe lien expire dans 30 minutes.");

            $mailer->send($mail);
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

        return $this->json([
            'success' => true,
            'message' => 'Si l’adresse existe et est active, un email de réinitialisation a été expédié.',
        ]);
    }

    #[Route('/reset-password', name: 'api_auth_reset_password', methods: ['POST'])]
    public function resetPassword(
        Request $request,
        PasswordResetService $resetService,
        AuditLogger $auditLogger
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];
        $tokenString = trim((string) ($data['token'] ?? $request->request->get('token', '')));
        $newPassword = (string) ($data['password'] ?? $request->request->get('password', ''));

        if ($tokenString === '' || $newPassword === '') {
            return $this->json(['error' => 'Le jeton et le nouveau mot de passe sont requis.'], Response::HTTP_BAD_REQUEST);
        }

        if (strlen($newPassword) < 8) {
            return $this->json(['error' => 'Le mot de passe doit contenir au moins 8 caractères.'], Response::HTTP_BAD_REQUEST);
        }

        $token = $resetService->findValidToken($tokenString);
        if (!$token instanceof PasswordResetToken) {
            return $this->json(['error' => 'Jeton de réinitialisation invalide ou expiré.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $token->getUser();
        if ($resetService->reset($token, $tokenString, $newPassword)) {
            $auditLogger->log(
                event: \App\Enum\AuditAction::PasswordReset,
                success: true,
                user: $user,
                request: $request,
                context: ['stage' => 'completed', 'email' => $user->getEmail()],
                entityType: 'User',
                entityId: (string) $user->getId()
            );
            return $this->json(['success' => true, 'message' => 'Mot de passe mis à jour avec succès.']);
        }

        return $this->json(['error' => 'Impossible de réinitialiser le mot de passe.'], Response::HTTP_BAD_REQUEST);
    }

    #[Route('/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'user' => $this->serializeUser($user),
        ]);
    }

    private function serializeUser(User $u): array
    {
        $permissions = [];
        foreach ($u->getAccessRoles() as $role) {
            foreach ($role->getPermissions() as $perm) {
                if ($perm->isActive()) {
                    $permissions[$perm->getCode()] = true;
                }
            }
        }

        return [
            'id' => $u->getId(),
            'email' => $u->getEmail(),
            'firstName' => $u->getFirstName(),
            'lastName' => $u->getLastName(),
            'fullName' => $u->getFullName(),
            'phone' => $u->getPhone(),
            'username' => $u->getUsername(),
            'status' => $u->getStatus()->value,
            'lastLoginAt' => $u->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
            'roles' => array_map(fn (AccessRole $r) => ['code' => $r->getCode(), 'label' => $r->getLabel()], $u->getAccessRoles()->toArray()),
            'permissions' => array_keys($permissions),
            'structure' => $u->getOrganizationUnit() ? [
                'id' => $u->getOrganizationUnit()->getId(),
                'code' => $u->getOrganizationUnit()->getCode(),
                'name' => $u->getOrganizationUnit()->getName(),
                'type' => $u->getOrganizationUnit()->getType()->value,
                'hierarchyPath' => $u->getOrganizationUnit()->getHierarchyPath(),
            ] : null,
        ];
    }
}
