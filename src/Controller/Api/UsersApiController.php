<?php

namespace App\Controller\Api;

use App\Entity\AccessRole;
use App\Entity\OrganizationUnit;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Repository\UserRepository;
use App\Service\AccessScopeService;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/users')]
final class UsersApiController extends AbstractController
{
    public function __construct(
        private AccessScopeService $accessScopeService,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('', name: 'api_users_list', methods: ['GET'])]
    public function list(Request $request, UserRepository $userRepository): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'USER_VIEW');

        $query = $request->query->get('q');
        $status = $request->query->get('status');
        $roleId = $request->query->getInt('role_id') ?: null;
        $structureId = $request->query->getInt('structure_id') ?: null;

        $users = $userRepository->findBySearchAndFilters($query, $status, $roleId, $structureId);

        return $this->json(array_map([$this, 'serializeUser'], $users));
    }

    #[Route('', name: 'api_users_create', methods: ['POST'])]
    public function create(
        Request $request,
        UserPasswordHasherInterface $hasher,
        AuditLogger $auditLogger
    ): JsonResponse {
        $this->accessScopeService->assertPermission($this->currentUser(), 'USER_CREATE');

        $data = json_decode($request->getContent(), true) ?? [];
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $firstName = trim((string) ($data['firstName'] ?? $data['first_name'] ?? ''));
        $lastName = trim((string) ($data['lastName'] ?? $data['last_name'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Adresse email valide obligatoire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($firstName === '' || $lastName === '') {
            return $this->json(['error' => 'Prénom et nom obligatoires.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($password === '' || strlen($password) < 8) {
            return $this->json(['error' => 'Mot de passe d’au moins 8 caractères obligatoire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->entityManager->getRepository(User::class)->findOneBy(['email' => $email])) {
            return $this->json(['error' => 'Cette adresse email est déjà utilisée.'], Response::HTTP_CONFLICT);
        }

        $user = new User($email, $firstName, $lastName);
        $user->setPassword($hasher->hashPassword($user, $password));

        if (!empty($data['phone'])) {
            $user->setPhone((string) $data['phone']);
        }
        if (!empty($data['username'])) {
            $user->setUsername((string) $data['username']);
        }

        // Structure
        if (!empty($data['structure_id']) || !empty($data['organizationUnitId'])) {
            $unitId = (int) ($data['structure_id'] ?? $data['organizationUnitId']);
            $unit = $this->entityManager->getRepository(OrganizationUnit::class)->find($unitId);
            if ($unit) {
                $user->setOrganizationUnit($unit);
            }
        }

        // Rôles
        if (!empty($data['roles']) && is_array($data['roles'])) {
            foreach ($data['roles'] as $roleIdOrCode) {
                $role = is_numeric($roleIdOrCode)
                    ? $this->entityManager->getRepository(AccessRole::class)->find((int) $roleIdOrCode)
                    : $this->entityManager->getRepository(AccessRole::class)->findOneBy(['code' => (string) $roleIdOrCode]);
                if ($role) {
                    $user->addAccessRole($role);
                }
            }
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $auditLogger->log(
            event: \App\Enum\AuditAction::UserCreated,
            success: true,
            user: $this->currentUser(),
            request: $request,
            context: [
                'created_user_id' => $user->getId(),
                'email' => $user->getEmail(),
                'roles' => array_map(fn (AccessRole $r) => $r->getCode(), $user->getAccessRoles()->toArray()),
            ],
            entityType: 'User',
            entityId: (string) $user->getId()
        );

        return $this->json($this->serializeUser($user), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_users_detail', methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'USER_VIEW');

        $user = $this->entityManager->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serializeUser($user));
    }

    #[Route('/{id}', name: 'api_users_update', methods: ['PUT'])]
    public function update(
        int $id,
        Request $request,
        UserPasswordHasherInterface $hasher,
        AuditLogger $auditLogger
    ): JsonResponse {
        $this->accessScopeService->assertPermission($this->currentUser(), 'USER_UPDATE');

        $user = $this->entityManager->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé.'], Response::HTTP_NOT_FOUND);
        }

        $previousRoles = array_values(array_map(fn (AccessRole $r) => $r->getCode(), $user->getAccessRoles()->toArray()));
        sort($previousRoles);
        $previousActive = $user->isActive();

        $data = json_decode($request->getContent(), true) ?? [];

        if (isset($data['firstName']) || isset($data['first_name'])) {
            $user->setFirstName((string) ($data['firstName'] ?? $data['first_name']));
        }
        if (isset($data['lastName']) || isset($data['last_name'])) {
            $user->setLastName((string) ($data['lastName'] ?? $data['last_name']));
        }
        if (isset($data['phone'])) {
            $user->setPhone($data['phone'] ? (string) $data['phone'] : null);
        }
        if (isset($data['username'])) {
            $user->setUsername($data['username'] ? (string) $data['username'] : null);
        }
        if (!empty($data['password'])) {
            $user->setPassword($hasher->hashPassword($user, (string) $data['password']));
        }

        if (array_key_exists('structure_id', $data) || array_key_exists('organizationUnitId', $data)) {
            $unitId = $data['structure_id'] ?? $data['organizationUnitId'];
            $unit = $unitId ? $this->entityManager->getRepository(OrganizationUnit::class)->find((int) $unitId) : null;
            $user->setOrganizationUnit($unit);
        }

        if (isset($data['roles']) && is_array($data['roles'])) {
            $user->clearAccessRoles();
            foreach ($data['roles'] as $roleIdOrCode) {
                $role = is_numeric($roleIdOrCode)
                    ? $this->entityManager->getRepository(AccessRole::class)->find((int) $roleIdOrCode)
                    : $this->entityManager->getRepository(AccessRole::class)->findOneBy(['code' => (string) $roleIdOrCode]);
                if ($role) {
                    $user->addAccessRole($role);
                }
            }
        }

        if (isset($data['status'])) {
            $status = AccountStatus::tryFrom(strtoupper((string) $data['status']));
            if ($status) {
                $user->setStatus($status);
            }
        }

        $this->entityManager->flush();

        $newRoles = array_values(array_map(fn (AccessRole $r) => $r->getCode(), $user->getAccessRoles()->toArray()));
        sort($newRoles);

        $auditLogger->log(
            event: \App\Enum\AuditAction::UserUpdated,
            success: true,
            user: $this->currentUser(),
            request: $request,
            context: ['target_user_id' => $user->getId(), 'email' => $user->getEmail()],
            entityType: 'User',
            entityId: (string) $user->getId()
        );

        if ($previousRoles !== $newRoles) {
            $auditLogger->log(
                event: \App\Enum\AuditAction::UserRoleChanged,
                success: true,
                user: $this->currentUser(),
                request: $request,
                context: [
                    'target_user_id' => $user->getId(),
                    'previous_roles' => $previousRoles,
                    'new_roles' => $newRoles,
                ],
                entityType: 'User',
                entityId: (string) $user->getId()
            );
        }

        if ($previousActive !== $user->isActive()) {
            $auditLogger->log(
                event: $user->isActive() ? \App\Enum\AuditAction::UserEnabled : \App\Enum\AuditAction::UserDisabled,
                success: true,
                user: $this->currentUser(),
                request: $request,
                context: ['target_user_id' => $user->getId(), 'email' => $user->getEmail()],
                entityType: 'User',
                entityId: (string) $user->getId()
            );
        }

        return $this->json($this->serializeUser($user));
    }

    #[Route('/{id}/activate', name: 'api_users_activate', methods: ['PATCH'])]
    public function activate(int $id, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'USER_ACTIVATE');

        $user = $this->entityManager->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé.'], Response::HTTP_NOT_FOUND);
        }

        $user->activate();
        $this->entityManager->flush();

        $auditLogger->log(
            event: \App\Enum\AuditAction::UserEnabled,
            success: true,
            user: $this->currentUser(),
            request: $request,
            context: ['target_user_id' => $user->getId(), 'email' => $user->getEmail()],
            entityType: 'User',
            entityId: (string) $user->getId()
        );

        return $this->json([
            'success' => true,
            'message' => 'Compte activé avec succès.',
            'user' => $this->serializeUser($user),
        ]);
    }

    #[Route('/{id}/deactivate', name: 'api_users_deactivate', methods: ['PATCH'])]
    public function deactivate(int $id, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'USER_DEACTIVATE');

        $user = $this->entityManager->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->json(['error' => 'Utilisateur non trouvé.'], Response::HTTP_NOT_FOUND);
        }

        $user->deactivate();
        $this->entityManager->flush();

        $auditLogger->log(
            event: \App\Enum\AuditAction::UserDisabled,
            success: true,
            user: $this->currentUser(),
            request: $request,
            context: ['target_user_id' => $user->getId(), 'email' => $user->getEmail()],
            entityType: 'User',
            entityId: (string) $user->getId()
        );

        return $this->json([
            'success' => true,
            'message' => 'Compte désactivé avec succès.',
            'user' => $this->serializeUser($user),
        ]);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Non authentifié.');
        }
        return $user;
    }

    private function serializeUser(User $u): array
    {
        return [
            'id' => $u->getId(),
            'email' => $u->getEmail(),
            'firstName' => $u->getFirstName(),
            'lastName' => $u->getLastName(),
            'fullName' => $u->getFullName(),
            'phone' => $u->getPhone(),
            'username' => $u->getUsername(),
            'status' => $u->getStatus()->value,
            'isActive' => $u->isActive(),
            'lastLoginAt' => $u->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $u->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'structure' => $u->getOrganizationUnit() ? [
                'id' => $u->getOrganizationUnit()->getId(),
                'code' => $u->getOrganizationUnit()->getCode(),
                'name' => $u->getOrganizationUnit()->getName(),
            ] : null,
            'roles' => array_map(fn (AccessRole $r) => [
                'id' => $r->getId(),
                'code' => $r->getCode(),
                'label' => $r->getLabel(),
            ], $u->getAccessRoles()->toArray()),
        ];
    }
}
