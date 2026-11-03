<?php

namespace App\Controller\Api;

use App\Entity\OrganizationUnit;
use App\Entity\User;
use App\Enum\StructureType;
use App\Service\AccessScopeService;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/structures')]
final class StructuresApiController extends AbstractController
{
    public function __construct(
        private AccessScopeService $accessScopeService,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('', name: 'api_structures_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'STRUCTURE_VIEW');

        $units = $this->entityManager->getRepository(OrganizationUnit::class)->findBy([], ['displayOrder' => 'ASC', 'name' => 'ASC']);

        // Filter according to user structure boundary if non-admin
        $filtered = $this->accessScopeService->filterAccessibleStructures($this->currentUser(), $units);

        return $this->json(array_map([$this, 'serializeUnit'], $filtered));
    }

    #[Route('', name: 'api_structures_create', methods: ['POST'])]
    public function create(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'STRUCTURE_CREATE');

        $data = json_decode($request->getContent(), true) ?? [];
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $typeString = (string) ($data['type'] ?? '');
        $type = StructureType::tryFrom(strtoupper($typeString));

        if ($code === '' || $name === '' || !$type) {
            return $this->json(['error' => 'Code, nom et type valide obligatoires.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->entityManager->getRepository(OrganizationUnit::class)->findOneBy(['code' => $code])) {
            return $this->json(['error' => 'Une structure avec ce code existe déjà.'], Response::HTTP_CONFLICT);
        }

        $unit = new OrganizationUnit($code, $name, $type);

        if (!empty($data['description'])) {
            $unit->setDescription(trim((string) $data['description']));
        }
        if (isset($data['displayOrder']) || isset($data['display_order'])) {
            $unit->setDisplayOrder((int) ($data['displayOrder'] ?? $data['display_order']));
        }

        if (!empty($data['parent_id']) || !empty($data['parentId'])) {
            $parentId = (int) ($data['parent_id'] ?? $data['parentId']);
            $parent = $this->entityManager->getRepository(OrganizationUnit::class)->find($parentId);
            if ($parent) {
                $unit->setParent($parent);
            }
        }

        $this->entityManager->persist($unit);
        $this->entityManager->flush();

        $auditLogger->log('STRUCTURE_CREATED', true, $this->currentUser(), $request, ['structure_code' => $unit->getCode()]);

        return $this->json($this->serializeUnit($unit), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_structures_detail', methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'STRUCTURE_VIEW');

        $unit = $this->entityManager->getRepository(OrganizationUnit::class)->find($id);
        if (!$unit) {
            return $this->json(['error' => 'Structure non trouvée.'], Response::HTTP_NOT_FOUND);
        }

        $this->accessScopeService->assertStructureAccess($this->currentUser(), $unit);

        return $this->json($this->serializeUnit($unit));
    }

    #[Route('/{id}', name: 'api_structures_update', methods: ['PUT'])]
    public function update(int $id, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'STRUCTURE_UPDATE');

        $unit = $this->entityManager->getRepository(OrganizationUnit::class)->find($id);
        if (!$unit) {
            return $this->json(['error' => 'Structure non trouvée.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (!empty($data['name'])) {
            $unit->setName(trim((string) $data['name']));
        }
        if (!empty($data['type'])) {
            $type = StructureType::tryFrom(strtoupper((string) $data['type']));
            if ($type) {
                $unit->setType($type);
            }
        }
        if (array_key_exists('description', $data)) {
            $unit->setDescription($data['description'] ? trim((string) $data['description']) : null);
        }
        if (isset($data['displayOrder']) || isset($data['display_order'])) {
            $unit->setDisplayOrder((int) ($data['displayOrder'] ?? $data['display_order']));
        }
        if (array_key_exists('parent_id', $data) || array_key_exists('parentId', $data)) {
            $parentId = $data['parent_id'] ?? $data['parentId'];
            $parent = $parentId ? $this->entityManager->getRepository(OrganizationUnit::class)->find((int) $parentId) : null;
            if ($parent && $parent->getId() !== $unit->getId()) {
                $unit->setParent($parent);
            } elseif ($parentId === null) {
                $unit->setParent(null);
            }
        }

        $this->entityManager->flush();

        $auditLogger->log('STRUCTURE_UPDATED', true, $this->currentUser(), $request, ['structure_code' => $unit->getCode()]);

        return $this->json($this->serializeUnit($unit));
    }

    #[Route('/{id}/activate', name: 'api_structures_activate', methods: ['PATCH'])]
    public function activate(int $id, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'STRUCTURE_UPDATE');

        $unit = $this->entityManager->getRepository(OrganizationUnit::class)->find($id);
        if (!$unit) {
            return $this->json(['error' => 'Structure non trouvée.'], Response::HTTP_NOT_FOUND);
        }

        $unit->setActive(true);
        $this->entityManager->flush();

        $auditLogger->log('STRUCTURE_ACTIVATED', true, $this->currentUser(), $request, ['structure_code' => $unit->getCode()]);

        return $this->json([
            'success' => true,
            'message' => 'Structure activée.',
            'structure' => $this->serializeUnit($unit),
        ]);
    }

    #[Route('/{id}/deactivate', name: 'api_structures_deactivate', methods: ['PATCH'])]
    public function deactivate(int $id, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'STRUCTURE_UPDATE');

        $unit = $this->entityManager->getRepository(OrganizationUnit::class)->find($id);
        if (!$unit) {
            return $this->json(['error' => 'Structure non trouvée.'], Response::HTTP_NOT_FOUND);
        }

        $unit->setActive(false);
        $this->entityManager->flush();

        $auditLogger->log('STRUCTURE_DEACTIVATED', true, $this->currentUser(), $request, ['structure_code' => $unit->getCode()]);

        return $this->json([
            'success' => true,
            'message' => 'Structure désactivée.',
            'structure' => $this->serializeUnit($unit),
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

    private function serializeUnit(OrganizationUnit $u): array
    {
        return [
            'id' => $u->getId(),
            'code' => $u->getCode(),
            'name' => $u->getName(),
            'type' => $u->getType()->value,
            'typeLabel' => $u->getType()->label(),
            'description' => $u->getDescription(),
            'displayOrder' => $u->getDisplayOrder(),
            'active' => $u->isActive(),
            'parent' => $u->getParent() ? [
                'id' => $u->getParent()->getId(),
                'code' => $u->getParent()->getCode(),
                'name' => $u->getParent()->getName(),
            ] : null,
            'childrenCount' => $u->getChildren()->count(),
            'hierarchyPath' => $u->getHierarchyPath(),
        ];
    }
}
