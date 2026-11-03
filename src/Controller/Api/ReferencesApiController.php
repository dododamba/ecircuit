<?php

namespace App\Controller\Api;

use App\Entity\AdministrativeCircuit;
use App\Entity\CircuitStep;
use App\Entity\OrganizationUnit;
use App\Entity\ReferenceItem;
use App\Entity\User;
use App\Enum\ReferenceCategory;
use App\Service\AccessScopeService;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReferencesApiController extends AbstractController
{
    public function __construct(
        private AccessScopeService $accessScopeService,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/api/reference/{category}', name: 'api_reference_list', methods: ['GET'])]
    public function list(string $category): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'REFERENCE_VIEW');

        if ($category === 'circuits') {
            return $this->listCircuits();
        }

        $enum = ReferenceCategory::fromSlug($category);
        if (!$enum) {
            return $this->json(['error' => sprintf('Catégorie inconnue "%s".', $category)], Response::HTTP_NOT_FOUND);
        }

        $items = $this->entityManager->getRepository(ReferenceItem::class)->findBy(['category' => $enum], ['displayOrder' => 'ASC', 'label' => 'ASC']);

        return $this->json(array_map([$this, 'serializeReference'], $items));
    }

    #[Route('/api/reference/{category}', name: 'api_reference_create', methods: ['POST'])]
    public function create(string $category, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'REFERENCE_CREATE');

        $enum = ReferenceCategory::fromSlug($category);
        if (!$enum) {
            return $this->json(['error' => sprintf('Catégorie inconnue "%s".', $category)], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $label = trim((string) ($data['label'] ?? ''));

        if ($code === '' || $label === '') {
            return $this->json(['error' => 'Code et libellé obligatoires.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $existing = $this->entityManager->getRepository(ReferenceItem::class)->findOneBy(['category' => $enum, 'code' => $code]);
        if ($existing) {
            return $this->json(['error' => 'Ce code existe déjà dans cette catégorie.'], Response::HTTP_CONFLICT);
        }

        $item = new ReferenceItem(
            $enum,
            $code,
            $label,
            !empty($data['description']) ? trim((string) $data['description']) : null,
            (int) ($data['displayOrder'] ?? $data['display_order'] ?? 0)
        );

        if (!empty($data['uiColor']) || !empty($data['ui_color'])) {
            $item->setUiColor(trim((string) ($data['uiColor'] ?? $data['ui_color'])));
        }

        $this->entityManager->persist($item);
        $this->entityManager->flush();

        $auditLogger->log('REFERENCE_CREATED', true, $this->currentUser(), $request, ['category' => $enum->value, 'code' => $code]);

        return $this->json($this->serializeReference($item), Response::HTTP_CREATED);
    }

    #[Route('/api/reference/{category}/{id}', name: 'api_reference_detail', methods: ['GET'])]
    public function detail(string $category, int $id): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'REFERENCE_VIEW');

        $item = $this->entityManager->getRepository(ReferenceItem::class)->find($id);
        if (!$item) {
            return $this->json(['error' => 'Élément introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serializeReference($item));
    }

    #[Route('/api/reference/{category}/{id}', name: 'api_reference_update', methods: ['PUT'])]
    public function update(string $category, int $id, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'REFERENCE_UPDATE');

        $item = $this->entityManager->getRepository(ReferenceItem::class)->find($id);
        if (!$item) {
            return $this->json(['error' => 'Élément introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        if (!empty($data['label'])) {
            $item->setLabel(trim((string) $data['label']));
        }
        if (array_key_exists('description', $data)) {
            $item->setDescription($data['description'] ? trim((string) $data['description']) : null);
        }
        if (array_key_exists('uiColor', $data) || array_key_exists('ui_color', $data)) {
            $color = $data['uiColor'] ?? $data['ui_color'];
            $item->setUiColor($color ? trim((string) $color) : null);
        }
        if (isset($data['displayOrder']) || isset($data['display_order'])) {
            $item->setDisplayOrder((int) ($data['displayOrder'] ?? $data['display_order']));
        }
        if (isset($data['active'])) {
            $item->setActive((bool) $data['active']);
        }

        $this->entityManager->flush();

        $auditLogger->log('REFERENCE_UPDATED', true, $this->currentUser(), $request, ['reference_id' => $item->getId(), 'code' => $item->getCode()]);

        return $this->json($this->serializeReference($item));
    }

    #[Route('/api/reference/{category}/{id}/toggle', name: 'api_reference_toggle', methods: ['PATCH'])]
    public function toggle(string $category, int $id, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'REFERENCE_UPDATE');

        $item = $this->entityManager->getRepository(ReferenceItem::class)->find($id);
        if (!$item) {
            return $this->json(['error' => 'Élément introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $item->setActive(!$item->isActive());
        $this->entityManager->flush();

        $auditLogger->log('REFERENCE_TOGGLED', true, $this->currentUser(), $request, ['code' => $item->getCode(), 'active' => $item->isActive()]);

        return $this->json([
            'success' => true,
            'message' => $item->isActive() ? 'Élément activé.' : 'Élément désactivé.',
            'item' => $this->serializeReference($item),
        ]);
    }

    #[Route('/api/circuits', name: 'api_circuits_list', methods: ['GET'])]
    public function listCircuits(): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'CIRCUIT_VIEW');

        $circuits = $this->entityManager->getRepository(AdministrativeCircuit::class)->findAll();

        return $this->json(array_map([$this, 'serializeCircuit'], $circuits));
    }

    #[Route('/api/circuits', name: 'api_circuits_create', methods: ['POST'])]
    public function createCircuit(Request $request, AuditLogger $auditLogger, \App\Service\CircuitWorkflowSyncService $circuitSyncService): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'CIRCUIT_CREATE');

        $data = json_decode($request->getContent(), true) ?? [];
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $label = trim((string) ($data['label'] ?? ''));
        $description = isset($data['description']) ? trim((string) $data['description']) : null;

        if ($code === '' || $label === '') {
            return $this->json(['error' => 'Code et libellé du circuit obligatoires.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->entityManager->getRepository(AdministrativeCircuit::class)->findOneBy(['code' => $code])) {
            return $this->json(['error' => 'Un circuit avec ce code existe déjà.'], Response::HTTP_CONFLICT);
        }

        $circuit = new AdministrativeCircuit($code, $label, $description);

        if (!empty($data['steps']) && is_array($data['steps'])) {
            foreach ($data['steps'] as $idx => $stepData) {
                $unitId = (int) ($stepData['structure_id'] ?? $stepData['organizationUnitId'] ?? $stepData['unitId'] ?? 0);
                $unit = $this->entityManager->getRepository(OrganizationUnit::class)->find($unitId);
                if ($unit) {
                    $dur = !empty($stepData['standardDurationHours'] ?? $stepData['standard_duration_hours']) ? (int) ($stepData['standardDurationHours'] ?? $stepData['standard_duration_hours']) : 48;
                    new CircuitStep($circuit, $unit, $idx + 1, !empty($stepData['label']) ? trim((string) $stepData['label']) : null, $dur);
                }
            }
        }

        $this->entityManager->persist($circuit);
        $this->entityManager->flush();

        // Synchronisation vers le moteur de workflow (S3.11)
        $circuitSyncService->syncCircuitToWorkflow($circuit);

        $auditLogger->log('CIRCUIT_CREATED', true, $this->currentUser(), $request, ['circuit_code' => $circuit->getCode()]);

        return $this->json($this->serializeCircuit($circuit), Response::HTTP_CREATED);
    }

    #[Route('/api/circuits/{id}', name: 'api_circuits_detail', methods: ['GET'])]
    public function detailCircuit(int $id): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'CIRCUIT_VIEW');

        $circuit = $this->entityManager->getRepository(AdministrativeCircuit::class)->find($id);
        if (!$circuit) {
            return $this->json(['error' => 'Circuit introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serializeCircuit($circuit));
    }

    #[Route('/api/circuits/{id}', name: 'api_circuits_update', methods: ['PUT'])]
    public function updateCircuit(int $id, Request $request, AuditLogger $auditLogger, \App\Service\CircuitWorkflowSyncService $circuitSyncService): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'CIRCUIT_UPDATE');

        $circuit = $this->entityManager->getRepository(AdministrativeCircuit::class)->find($id);
        if (!$circuit) {
            return $this->json(['error' => 'Circuit introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        if (!empty($data['label'])) {
            $circuit->setLabel(trim((string) $data['label']));
        }
        if (array_key_exists('description', $data)) {
            $circuit->setDescription($data['description'] ? trim((string) $data['description']) : null);
        }
        if (isset($data['active'])) {
            $circuit->setActive((bool) $data['active']);
        }

        if (isset($data['steps']) && is_array($data['steps'])) {
            $circuit->clearSteps();
            foreach ($data['steps'] as $idx => $stepData) {
                $unitId = (int) ($stepData['structure_id'] ?? $stepData['organizationUnitId'] ?? $stepData['unitId'] ?? 0);
                $unit = $this->entityManager->getRepository(OrganizationUnit::class)->find($unitId);
                if ($unit) {
                    $dur = !empty($stepData['standardDurationHours'] ?? $stepData['standard_duration_hours']) ? (int) ($stepData['standardDurationHours'] ?? $stepData['standard_duration_hours']) : 48;
                    new CircuitStep($circuit, $unit, $idx + 1, !empty($stepData['label']) ? trim((string) $stepData['label']) : null, $dur);
                }
            }
        }

        $this->entityManager->flush();

        // Synchronisation vers le moteur de workflow (S3.11)
        $circuitSyncService->syncCircuitToWorkflow($circuit);

        $auditLogger->log('CIRCUIT_UPDATED', true, $this->currentUser(), $request, ['circuit_code' => $circuit->getCode()]);

        return $this->json($this->serializeCircuit($circuit));
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Non authentifié.');
        }
        return $user;
    }

    private function serializeReference(ReferenceItem $i): array
    {
        return [
            'id' => $i->getId(),
            'category' => $i->getCategory()->value,
            'categoryLabel' => $i->getCategory()->label(),
            'code' => $i->getCode(),
            'label' => $i->getLabel(),
            'description' => $i->getDescription(),
            'uiColor' => $i->getUiColor(),
            'displayOrder' => $i->getDisplayOrder(),
            'active' => $i->isActive(),
        ];
    }

    private function serializeCircuit(AdministrativeCircuit $c): array
    {
        return [
            'id' => $c->getId(),
            'code' => $c->getCode(),
            'label' => $c->getLabel(),
            'description' => $c->getDescription(),
            'active' => $c->isActive(),
            'stepsCount' => $c->getSteps()->count(),
            'steps' => array_map(fn (CircuitStep $s) => [
                'position' => $s->getPosition(),
                'label' => $s->getLabel(),
                'structure' => [
                    'id' => $s->getOrganizationUnit()->getId(),
                    'code' => $s->getOrganizationUnit()->getCode(),
                    'name' => $s->getOrganizationUnit()->getName(),
                    'type' => $s->getOrganizationUnit()->getType()->value,
                ],
            ], $c->getSteps()->toArray()),
        ];
    }
}
