<?php

namespace App\Controller\Api;

use App\Entity\AccessPermission;
use App\Entity\AccessRole;
use App\Entity\User;
use App\Service\AccessScopeService;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RolesApiController extends AbstractController
{
    public function __construct(
        private AccessScopeService $accessScopeService,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/api/roles', name: 'api_roles_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'ROLE_VIEW');

        $roles = $this->entityManager->getRepository(AccessRole::class)->findAll();

        return $this->json(array_map([$this, 'serializeRole'], $roles));
    }

    #[Route('/api/roles', name: 'api_roles_create', methods: ['POST'])]
    public function create(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'ROLE_CREATE');

        $data = json_decode($request->getContent(), true) ?? [];
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $label = trim((string) ($data['label'] ?? ''));
        $description = isset($data['description']) ? trim((string) $data['description']) : null;

        if ($code === '' || $label === '') {
            return $this->json(['error' => 'Code et libellé obligatoires.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->entityManager->getRepository(AccessRole::class)->findOneBy(['code' => $code])) {
            return $this->json(['error' => 'Ce code de rôle existe déjà.'], Response::HTTP_CONFLICT);
        }

        $role = new AccessRole($code, $label, $description);

        if (!empty($data['permissions']) && is_array($data['permissions'])) {
            $this->accessScopeService->assertPermission($this->currentUser(), 'PERMISSION_ASSIGN');
            foreach ($data['permissions'] as $permCode) {
                $perm = $this->entityManager->getRepository(AccessPermission::class)->findOneBy(['code' => (string) $permCode]);
                if ($perm) {
                    $role->grant($perm);
                }
            }
        }

        $this->entityManager->persist($role);
        $this->entityManager->flush();

        $auditLogger->log('ROLE_CREATED', true, $this->currentUser(), $request, ['role_code' => $role->getCode()]);

        return $this->json($this->serializeRole($role), Response::HTTP_CREATED);
    }

    #[Route('/api/roles/{id}', name: 'api_roles_detail', methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'ROLE_VIEW');

        $role = $this->entityManager->getRepository(AccessRole::class)->find($id);
        if (!$role) {
            return $this->json(['error' => 'Rôle non trouvé.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serializeRole($role));
    }

    #[Route('/api/roles/{id}', name: 'api_roles_update', methods: ['PUT'])]
    public function update(int $id, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'ROLE_UPDATE');

        $role = $this->entityManager->getRepository(AccessRole::class)->find($id);
        if (!$role) {
            return $this->json(['error' => 'Rôle non trouvé.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        if (!empty($data['label'])) {
            $role->setLabel(trim((string) $data['label']));
        }
        if (array_key_exists('description', $data)) {
            $role->setDescription($data['description'] ? trim((string) $data['description']) : null);
        }

        $previousPermissions = array_values(array_map(fn (AccessPermission $p) => $p->getCode(), $role->getPermissions()->toArray()));
        sort($previousPermissions);

        if (isset($data['permissions']) && is_array($data['permissions'])) {
            $this->accessScopeService->assertPermission($this->currentUser(), 'PERMISSION_ASSIGN');
            $role->clearPermissions();
            foreach ($data['permissions'] as $permCode) {
                $perm = $this->entityManager->getRepository(AccessPermission::class)->findOneBy(['code' => (string) $permCode]);
                if ($perm) {
                    $role->grant($perm);
                }
            }
        }

        $this->entityManager->flush();

        $newPermissions = array_values(array_map(fn (AccessPermission $p) => $p->getCode(), $role->getPermissions()->toArray()));
        sort($newPermissions);

        $auditLogger->log(
            event: \App\Enum\AuditAction::RoleUpdated,
            success: true,
            user: $this->currentUser(),
            request: $request,
            context: ['role_code' => $role->getCode(), 'role_id' => $role->getId()],
            entityType: 'AccessRole',
            entityId: (string) $role->getId()
        );

        if ($previousPermissions !== $newPermissions) {
            $auditLogger->log(
                event: \App\Enum\AuditAction::UserPermissionChanged,
                success: true,
                user: $this->currentUser(),
                request: $request,
                context: [
                    'role_code' => $role->getCode(),
                    'role_id' => $role->getId(),
                    'previous_permissions' => $previousPermissions,
                    'new_permissions' => $newPermissions,
                ],
                entityType: 'AccessRole',
                entityId: (string) $role->getId()
            );
        }

        return $this->json($this->serializeRole($role));
    }

    #[Route('/api/permissions', name: 'api_permissions_list', methods: ['GET'])]
    public function permissions(): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'PERMISSION_VIEW');

        $perms = $this->entityManager->getRepository(AccessPermission::class)->findBy([], ['module' => 'ASC', 'code' => 'ASC']);

        return $this->json(array_map(fn (AccessPermission $p) => [
            'id' => $p->getId(),
            'code' => $p->getCode(),
            'label' => $p->getLabel(),
            'module' => $p->getModule(),
            'active' => $p->isActive(),
        ], $perms));
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Non authentifié.');
        }
        return $user;
    }

    private function serializeRole(AccessRole $r): array
    {
        return [
            'id' => $r->getId(),
            'code' => $r->getCode(),
            'label' => $r->getLabel(),
            'description' => $r->getDescription(),
            'permissions' => array_map(fn (AccessPermission $p) => [
                'code' => $p->getCode(),
                'label' => $p->getLabel(),
                'module' => $p->getModule(),
            ], $r->getPermissions()->toArray()),
        ];
    }
}
