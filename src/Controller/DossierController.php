<?php

namespace App\Controller;

use App\Entity\CaseEvent;
use App\Entity\CaseTransmission;
use App\Entity\Document;
use App\Entity\DossierCase;
use App\Entity\OrganizationUnit;
use App\Entity\ReferenceItem;
use App\Entity\User;
use App\Enum\CaseEventType;
use App\Enum\ReferenceCategory;
use App\Repository\CaseEventRepository;
use App\Repository\DossierCaseRepository;
use App\Service\AccessScopeService;
use App\Service\AuditLogger;
use App\Service\DocumentUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DossierController extends AbstractController
{
    public function __construct(
        private readonly AccessScopeService $access,
        private readonly DossierCaseRepository $caseRepository,
        private readonly CaseEventRepository $caseEventRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentUploader $documentUploader,
        private readonly AuditLogger $auditLogger,
        private readonly \App\Service\WorkflowService $workflowService
    ) {}

    private function user(): User
    {
        /** @var User $user */
        $user = $this->getUser();
        return $user;
    }

    /**
     * Liste et filtre général des dossiers administratifs
     */
    /**
     * Liste et filtre général des dossiers administratifs avec les 6 scopes fonctionnels (Module 8)
     */
    #[Route('/dossiers', name: 'dossier_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $this->access->assertPermission($this->user(), 'DOSSIER_VIEW');

        $search = $request->query->get('search');
        $status = $request->query->get('status');
        $priority = $request->query->get('priority');
        $channel = $request->query->get('channel');
        $structureId = $request->query->getInt('structure_id') ?: null;
        $scope = $request->query->get('scope', 'all');
        $scopeValid = in_array($scope, ['mine', 'received', 'to_treat', 'in_progress', 'returned', 'completed', 'all'], true) ? $scope : 'all';

        $page = max(1, $request->query->getInt('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $baseFilters = [
            'search' => $search,
            'status' => $status,
            'priority' => $priority,
            'channel' => $channel,
            'structure_id' => $structureId,
        ];

        $filters = $baseFilters;
        $filters['scope'] = $scopeValid !== 'all' ? $scopeValid : null;
        $filters['current_user'] = $this->user();

        $cases = $this->caseRepository->findByFilters($filters, $limit, $offset);
        $totalCount = $this->caseRepository->countByFilters($filters);
        $totalPages = max(1, (int) ceil($totalCount / $limit));

        $scopeCounts = $this->caseRepository->getScopeCounts($this->user(), $baseFilters);

        $structures = $this->entityManager->getRepository(OrganizationUnit::class)->findBy(
            ['active' => true],
            ['name' => 'ASC']
        );

        $statuses = $this->entityManager->getRepository(ReferenceItem::class)->findBy([
            'category' => ReferenceCategory::DossierStatus,
            'active' => true,
        ], ['displayOrder' => 'ASC']);

        $priorities = $this->entityManager->getRepository(ReferenceItem::class)->findBy([
            'category' => ReferenceCategory::Priority,
            'active' => true,
        ], ['displayOrder' => 'ASC']);

        return $this->render('dossier/list.html.twig', [
            'cases' => $cases,
            'total_count' => $totalCount,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'filters' => $baseFilters,
            'active_scope' => $scopeValid,
            'scope_counts' => $scopeCounts,
            'structures' => $structures,
            'statuses' => $statuses,
            'priorities' => $priorities,
        ]);
    }

    /**
     * Vue détaillée d'un dossier avec structure à 5 onglets Metronic
     */
    #[Route('/dossiers/{id}', name: 'dossier_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(DossierCase $case, Request $request): Response
    {
        $this->access->assertPermission($this->user(), 'DOSSIER_VIEW');
        $this->access->assertDossierAccess($this->user(), $case);

        $activeTab = $request->query->get('tab', 'overview');

        $structures = $this->entityManager->getRepository(OrganizationUnit::class)->findBy(
            ['active' => true],
            ['name' => 'ASC']
        );

        $agents = $this->entityManager->getRepository(User::class)->findBy(
            [],
            ['lastName' => 'ASC', 'firstName' => 'ASC']
        );

        $transmissionReasons = $this->entityManager->getRepository(ReferenceItem::class)->findBy([
            'category' => ReferenceCategory::TransmissionReason,
            'active' => true,
        ], ['displayOrder' => 'ASC']);

        // Démarrage ou récupération de l'instance de workflow pour le dossier
        $instance = null;
        $availableTransitions = [];
        $workflowSteps = [];

        try {
            $instance = $this->workflowService->start($case);
            $availableTransitions = $this->workflowService->getAvailableTransitions($case, $this->user());
            $workflowSteps = $instance->getWorkflow()->getActiveSteps();
        } catch (\Throwable) {
            // Tolérance si aucun schéma de workflow n'est actif
        }

        $user = $this->user();
        $userUnit = $user->getOrganizationUnit();
        $isClosedOrArchived = $case->isClosed() || $case->isArchived();

        // Droits fonctionnels contextualisés
        $canAssign = !$isClosedOrArchived && $this->access->hasPermission($user, 'DOSSIER_ASSIGN');
        $canTreat = !$isClosedOrArchived && $this->access->hasPermission($user, 'DOSSIER_TREAT');
        $canTakeInCharge = $canTreat && ($case->getCurrentAssignee() === null || $case->getCurrentAssignee()->getId() === $user->getId()) && !in_array($case->getStatusCode(), ['IN_PROGRESS', 'IN_ANALYSIS'], true);
        $canReturn = !$isClosedOrArchived && ($this->access->hasPermission($user, 'DOSSIER_FORWARD') || $this->access->hasPermission($user, 'DOSSIER_VALIDATE'));
        $canForward = !$isClosedOrArchived && $this->access->hasPermission($user, 'DOSSIER_FORWARD');

        // Traçabilité et historique chronologique (Module 9)
        $eventCategory = $request->query->get('event_category', 'all');
        $validCategories = ['all', 'workflow', 'documents', 'affectations', 'validations'];
        if (!in_array($eventCategory, $validCategories, true)) {
            $eventCategory = 'all';
        }
        $eventPage = max(1, $request->query->getInt('event_page', 1));
        $eventLimit = 25;
        $eventOffset = ($eventPage - 1) * $eventLimit;

        $timelineEvents = $this->caseEventRepository->findByCaseWithFilters(
            $case,
            $eventCategory,
            order: 'ASC',
            limit: $eventLimit,
            offset: $eventOffset
        );
        $totalTimelineEvents = $this->caseEventRepository->countByCaseWithFilters($case, $eventCategory);
        $totalEventPages = max(1, (int) ceil($totalTimelineEvents / $eventLimit));
        $eventCategoryCounts = $this->caseEventRepository->getCategoryCounts($case);

        return $this->render('dossier/show.html.twig', [
            'case' => $case,
            'active_tab' => $activeTab,
            'structures' => $structures,
            'agents' => $agents,
            'transmission_reasons' => $transmissionReasons,
            'workflow_instance' => $instance,
            'active_task' => $instance?->getActiveTask(),
            'available_transitions' => $availableTransitions,
            'workflow_steps' => $workflowSteps,
            'can_assign' => $canAssign,
            'can_treat' => $canTreat,
            'can_take_in_charge' => $canTakeInCharge,
            'can_return' => $canReturn,
            'can_forward' => $canForward,
            'timeline_events' => $timelineEvents,
            'active_event_category' => $eventCategory,
            'event_category_counts' => $eventCategoryCounts,
            'current_event_page' => $eventPage,
            'total_event_pages' => $totalEventPages,
            'total_timeline_events' => $totalTimelineEvents,
        ]);
    }

    /**
     * Flux JSON des événements de traçabilité (Module 9 - Chargement progressif & API)
     */
    #[Route('/dossiers/{id}/timeline/events', name: 'dossier_timeline_events', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function timelineEvents(DossierCase $case, Request $request): JsonResponse
    {
        $this->access->assertPermission($this->user(), 'DOSSIER_VIEW');
        $this->access->assertDossierAccess($this->user(), $case);

        $category = $request->query->get('category', 'all');
        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, min(100, $request->query->getInt('limit', 25)));
        $offset = ($page - 1) * $limit;

        $events = $this->caseEventRepository->findByCaseWithFilters(
            $case,
            $category,
            order: 'ASC',
            limit: $limit,
            offset: $offset
        );
        $total = $this->caseEventRepository->countByCaseWithFilters($case, $category);
        $counts = $this->caseEventRepository->getCategoryCounts($case);

        $data = array_map(fn(CaseEvent $e) => [
            'id' => $e->getId(),
            'action' => $e->getAction(),
            'event_type' => $e->getEventType()->value,
            'label' => $e->getLabel(),
            'description' => $e->getDescription(),
            'comment' => $e->getComment(),
            'occurred_at' => $e->getOccurredAt()->format('Y-m-d H:i:s'),
            'date_formatted' => $e->getOccurredAt()->format('d/m/Y'),
            'time_formatted' => $e->getOccurredAt()->format('H:i:s'),
            'actor' => $e->getActor() ? [
                'id' => $e->getActor()->getId(),
                'name' => $e->getActor()->getFullName(),
                'email' => $e->getActor()->getEmail(),
            ] : null,
            'structure' => $e->getOrganizationUnit() ? [
                'id' => $e->getOrganizationUnit()->getId(),
                'code' => $e->getOrganizationUnit()->getCode(),
                'name' => $e->getOrganizationUnit()->getName(),
            ] : null,
            'old_value' => $e->getOldValue(),
            'new_value' => $e->getNewValue(),
            'metadata' => $e->getMetadata(),
            'category' => $e->getCategory(),
            'keen_icon' => $e->getEventType()->keenIcon(),
            'badge_class' => $e->getEventType()->badgeClass(),
        ], $events);

        return new JsonResponse([
            'success' => true,
            'total' => $total,
            'page' => $page,
            'total_pages' => max(1, (int) ceil($total / $limit)),
            'category' => $category,
            'category_counts' => $counts,
            'events' => $data,
        ]);
    }

    /**
     * Affectation du dossier à un agent ou à une structure (Module 8)
     */
    #[Route('/dossiers/{id}/assign', name: 'dossier_assign', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function assign(DossierCase $case, Request $request): Response
    {
        $this->access->assertPermission($this->user(), 'DOSSIER_ASSIGN');

        if (!$this->isCsrfTokenValid('dossier_assign_'.$case->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if ($case->isClosed() || $case->isArchived()) {
            $this->addFlash('error', 'Impossible d\'affecter un dossier déjà clôturé ou archivé.');
            return $this->redirectToRoute('dossier_show', ['id' => $case->getId()]);
        }

        // Vérification de la portée organisationnelle
        $currentStructure = $case->getCurrentStructure();
        if ($currentStructure !== null && !$this->user()->hasAccessRole('ADMINISTRATEUR')) {
            $this->access->assertStructureScope($this->user(), $currentStructure);
        }

        $assigneeId = $request->request->getInt('assignee_id');
        $structureId = $request->request->getInt('structure_id');
        $instructions = trim((string) $request->request->get('instructions'));
        $deadlineStr = trim((string) $request->request->get('deadline'));

        $assignee = $assigneeId > 0 ? $this->entityManager->getRepository(User::class)->find($assigneeId) : null;
        $structure = $structureId > 0 ? $this->entityManager->getRepository(OrganizationUnit::class)->find($structureId) : null;

        $oldAssignee = $case->getCurrentAssignee();
        $oldStructure = $case->getCurrentStructure();

        if ($assignee !== null) {
            $case->setCurrentAssignee($assignee);
            if ($assignee->getOrganizationUnit() !== null && $structure === null) {
                $case->setCurrentStructure($assignee->getOrganizationUnit());
            }
        }
        if ($structure !== null) {
            $case->setCurrentStructure($structure);
        }

        $deadline = null;
        if ($deadlineStr !== '') {
            try {
                $deadline = new \DateTimeImmutable($deadlineStr);
                $case->setDeadline($deadline);
            } catch (\Throwable) {}
        }

        $case->setStatusCode('IN_ANALYSIS');

        // Synchronisation de la tâche active si existante
        $activeTask = $case->getWorkflowInstance()?->getActiveTask();
        if ($activeTask !== null) {
            if ($assignee !== null) {
                $activeTask->setTargetUser($assignee);
            }
            if ($structure !== null) {
                $activeTask->setTargetStructure($structure);
            }
            if ($instructions !== '') {
                $activeTask->setInstructions($instructions);
            }
            if ($deadline !== null) {
                $activeTask->setDeadline($deadline);
            }
        }

        // Événement de traçabilité (ASSIGNED / DOSSIER_AFFECTE - Module 9)
        $eventLabel = $assignee !== null
            ? sprintf('Affectation du dossier à %s', $assignee->getFullName())
            : sprintf('Affectation à la structure %s', $structure?->getName() ?? 'N/A');

        $event = new CaseEvent(
            $case,
            CaseEventType::CaseAssigned,
            $eventLabel,
            $this->user(),
            $instructions !== '' ? $instructions : null,
            action: 'ASSIGNED',
            oldValue: $oldAssignee?->getFullName() ?? $oldStructure?->getName() ?? 'Non affecté',
            newValue: $assignee?->getFullName() ?? $structure?->getName() ?? 'N/A',
            organizationUnit: $this->user()->getOrganizationUnit(),
            metadata: [
                'action' => 'ASSIGNED',
                'assignee' => $assignee?->getFullName(),
                'structure' => $case->getCurrentStructure()?->getName(),
                'instructions' => $instructions !== '' ? $instructions : null,
                'deadline' => $case->getDeadline()?->format('Y-m-d'),
            ]
        );
        $case->addEvent($event);

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        $this->auditLogger->log(
            event: \App\Enum\AuditAction::DossierAssigned,
            success: true,
            user: $this->user(),
            request: $request,
            context: [
                'reference' => $case->getReference(),
                'assignee' => $assignee?->getFullName(),
                'assignee_id' => $assignee?->getId(),
                'structure' => $case->getCurrentStructure()?->getName(),
                'deadline' => $case->getDeadline()?->format('Y-m-d'),
            ],
            entityType: 'DossierCase',
            entityId: (string) $case->getId()
        );

        $this->addFlash('success', 'Le dossier a été affecté avec succès.');

        return $this->redirectToRoute('dossier_show', ['id' => $case->getId(), 'tab' => 'timeline']);
    }

    /**
     * Prise en charge opérationnelle du dossier par un agent (Module 8 & 9)
     */
    #[Route('/dossiers/{id}/take-in-charge', name: 'dossier_take_in_charge', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function takeInCharge(DossierCase $case, Request $request): Response
    {
        $this->access->assertPermission($this->user(), 'DOSSIER_TREAT');

        if (!$this->isCsrfTokenValid('dossier_take_in_charge_'.$case->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if ($case->isClosed() || $case->isArchived()) {
            $this->addFlash('error', 'Ce dossier est déjà clôturé ou archivé.');
            return $this->redirectToRoute('dossier_show', ['id' => $case->getId()]);
        }

        // Vérification de la portée organisationnelle de l'agent
        $currentStructure = $case->getCurrentStructure();
        if ($currentStructure !== null && !$this->user()->hasAccessRole('ADMINISTRATEUR')) {
            $this->access->assertStructureScope($this->user(), $currentStructure);
        }

        // Si le dossier était déjà affecté à un autre agent précis, seul cet agent ou un supérieur peut prendre en charge
        if ($case->getCurrentAssignee() !== null && $case->getCurrentAssignee()->getId() !== $this->user()->getId()) {
            if (!$this->access->hasPermission($this->user(), 'DOSSIER_ASSIGN') && !$this->user()->hasAccessRole('ADMINISTRATEUR')) {
                throw $this->createAccessDeniedException('Ce dossier est déjà affecté nominativement à un autre agent.');
            }
        }

        $oldAssignee = $case->getCurrentAssignee();

        // Exécution via WorkflowService si transition disponible, sinon mise à jour directe
        if ($this->workflowService->canExecute($case, \App\Enum\WorkflowAction::TakeInCharge, $this->user())) {
            $this->workflowService->takeInCharge($case, $this->user());
        } else {
            $case->setCurrentAssignee($this->user());
            $case->setStatusCode('IN_PROGRESS');

            $activeTask = $case->getWorkflowInstance()?->getActiveTask();
            if ($activeTask !== null) {
                $activeTask->setTargetUser($this->user());
                $activeTask->start();
            }

            $event = new CaseEvent(
                $case,
                CaseEventType::TakenInCharge,
                sprintf('Prise en charge du dossier par %s', $this->user()->getFullName()),
                $this->user(),
                'Dossier pris en charge pour instruction opérationnelle.',
                action: 'TAKEN_IN_CHARGE',
                oldValue: $oldAssignee?->getFullName() ?? 'Non assigné',
                newValue: $this->user()->getFullName(),
                organizationUnit: $this->user()->getOrganizationUnit() ?? $currentStructure,
                metadata: [
                    'action' => 'TAKEN_IN_CHARGE',
                    'agent' => $this->user()->getFullName(),
                    'date' => (new \DateTimeImmutable())->format('c'),
                ]
            );
            $case->addEvent($event);
            $this->entityManager->persist($event);
            $this->entityManager->flush();

            $this->auditLogger->log(
                event: \App\Enum\AuditAction::DossierStatusChanged,
                success: true,
                user: $this->user(),
                request: $request,
                context: [
                    'reference' => $case->getReference(),
                    'new_status' => 'IN_PROGRESS',
                    'agent' => $this->user()->getFullName(),
                ],
                entityType: 'DossierCase',
                entityId: (string) $case->getId()
            );
        }

        $this->addFlash('success', 'Vous avez pris en charge ce dossier avec succès.');

        return $this->redirectToRoute('dossier_show', ['id' => $case->getId()]);
    }

    /**
     * Retour d'un dossier avec motif obligatoire (Module 8 & 9)
     */
    #[Route('/dossiers/{id}/return', name: 'dossier_return', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function returnCase(DossierCase $case, Request $request): Response
    {
        $user = $this->user();
        if (!$this->access->hasPermission($user, 'DOSSIER_FORWARD') && !$this->access->hasPermission($user, 'DOSSIER_VALIDATE')) {
            throw $this->createAccessDeniedException('Permission insuffisante pour retourner ce dossier.');
        }
        $this->access->assertDossierAccess($user, $case);

        if (!$this->isCsrfTokenValid('dossier_return_'.$case->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if ($case->isClosed() || $case->isArchived()) {
            $this->addFlash('error', 'Impossible de retourner un dossier déjà clôturé ou archivé.');
            return $this->redirectToRoute('dossier_show', ['id' => $case->getId()]);
        }

        $reason = trim((string) $request->request->get('reason'));
        if ($reason === '') {
            $this->addFlash('error', 'Le motif du retour est obligatoire.');
            return $this->redirectToRoute('dossier_show', ['id' => $case->getId()]);
        }

        $comment = trim((string) $request->request->get('comment'));
        $targetUnitId = $request->request->getInt('target_unit_id');
        $targetUnit = $targetUnitId > 0 ? $this->entityManager->getRepository(OrganizationUnit::class)->find($targetUnitId) : null;

        if ($this->workflowService->canExecute($case, \App\Enum\WorkflowAction::Return, $user)) {
            $this->workflowService->return($case, $user, $reason, $comment !== '' ? $comment : null, $targetUnit);
        } else {
            $sourceUnit = $case->getCurrentStructure() ?? $user->getOrganizationUnit();
            if ($targetUnit !== null) {
                $case->setCurrentStructure($targetUnit);
            }
            $case->setStatusCode('RETURNED');

            $fullReason = $reason . ($comment !== '' ? ' — ' . $comment : '');

            $event = new CaseEvent(
                $case,
                CaseEventType::Returned,
                sprintf('Retour du dossier : %s', $reason),
                $user,
                $fullReason,
                action: 'RETURNED',
                oldValue: $sourceUnit?->getName() ?? 'Direction',
                newValue: $targetUnit?->getName() ?? 'Agent instructeur',
                organizationUnit: $sourceUnit,
                metadata: [
                    'action' => 'RETURNED',
                    'reason' => $reason,
                    'comment' => $comment,
                    'target_structure' => $targetUnit?->getName(),
                ]
            );
            $case->addEvent($event);
            $this->entityManager->persist($event);
            $this->entityManager->flush();

            $this->auditLogger->log(
                event: \App\Enum\AuditAction::WorkflowReturned,
                success: true,
                user: $user,
                request: $request,
                context: [
                    'reference' => $case->getReference(),
                    'reason' => $reason,
                    'target_structure' => $targetUnit?->getCode(),
                ],
                entityType: 'DossierCase',
                entityId: (string) $case->getId()
            );
        }

        $this->addFlash('warning', sprintf('Le dossier %s a été retourné : %s', $case->getReference(), $reason));

        return $this->redirectToRoute('dossier_show', ['id' => $case->getId()]);
    }

    /**
     * Transmission inter-services du dossier (Module 9)
     */
    #[Route('/dossiers/{id}/transmit', name: 'dossier_transmit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function transmit(DossierCase $case, Request $request): Response
    {
        $this->access->assertPermission($this->user(), 'DOSSIER_FORWARD');
        $this->access->assertDossierAccess($this->user(), $case);

        if (!$this->isCsrfTokenValid('dossier_transmit_'.$case->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $targetUnitId = $request->request->getInt('target_unit_id');
        $targetUnit = $targetUnitId > 0 ? $this->entityManager->getRepository(OrganizationUnit::class)->find($targetUnitId) : null;

        if (!$targetUnit) {
            $this->addFlash('error', 'La structure destinataire est obligatoire pour une transmission.');
            return $this->redirectToRoute('dossier_show', ['id' => $case->getId(), 'tab' => 'transmissions']);
        }

        $reason = trim((string) $request->request->get('reason'));
        $instructions = trim((string) $request->request->get('instructions'));

        $sourceUnit = $case->getCurrentStructure() ?? $this->user()->getOrganizationUnit();

        $transmission = new CaseTransmission(
            $case,
            $sourceUnit,
            $targetUnit,
            $this->user(),
            $reason !== '' ? $reason : 'Pour attribution',
            $instructions !== '' ? $instructions : null
        );

        $case->addTransmission($transmission);
        $case->setCurrentStructure($targetUnit);
        $case->setCurrentAssignee(null); // Réinitialisation de l'assignataire individuel lors du changement de structure
        $case->setStatusCode('TRANSMITTED');

        $this->entityManager->persist($transmission);

        // Événement chronologique (FORWARDED - Module 9)
        $event = new CaseEvent(
            $case,
            CaseEventType::CaseForwarded,
            sprintf('Transmission vers %s (%s)', $targetUnit->getName(), $reason !== '' ? $reason : 'Pour attribution'),
            $this->user(),
            $instructions !== '' ? $instructions : null,
            action: 'FORWARDED',
            oldValue: $sourceUnit?->getName(),
            newValue: $targetUnit->getName(),
            organizationUnit: $sourceUnit,
            metadata: [
                'action' => 'FORWARDED',
                'source' => $sourceUnit?->getName(),
                'target' => $targetUnit->getName(),
                'reason' => $reason,
            ]
        );
        $case->addEvent($event);
        $this->entityManager->persist($event);

        $this->entityManager->flush();

        $this->auditLogger->log(
            event: \App\Enum\AuditAction::DossierForwarded,
            success: true,
            user: $this->user(),
            request: $request,
            context: [
                'reference' => $case->getReference(),
                'source' => $sourceUnit?->getCode(),
                'target' => $targetUnit->getCode(),
                'reason' => $reason,
            ],
            entityType: 'DossierCase',
            entityId: (string) $case->getId()
        );

        $this->addFlash('success', sprintf('Le dossier a été transmis à %s.', $targetUnit->getName()));

        return $this->redirectToRoute('dossier_show', ['id' => $case->getId(), 'tab' => 'transmissions']);
    }

    /**
     * Clôture du dossier (Module 9)
     */
    #[Route('/dossiers/{id}/close', name: 'dossier_close', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function close(DossierCase $case, Request $request): Response
    {
        $user = $this->user();
        if (!$this->access->hasPermission($user, 'DOSSIER_CLOSE') && !$this->access->hasPermission($user, 'DOSSIER_VALIDATE')) {
            throw $this->createAccessDeniedException('Permission insuffisante pour clôturer ce dossier.');
        }
        $this->access->assertDossierAccess($user, $case);

        if (!$this->isCsrfTokenValid('dossier_close_'.$case->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $oldStatus = $case->getStatusCode();
        $case->close($this->user());

        $event = new CaseEvent(
            $case,
            CaseEventType::Completed,
            'Clôture administrative du dossier',
            $this->user(),
            'Le traitement de ce dossier est achevé et clôturé conformément aux instructions.',
            action: 'COMPLETED',
            oldValue: $oldStatus,
            newValue: 'COMPLETED',
            organizationUnit: $this->user()->getOrganizationUnit(),
            metadata: [
                'action' => 'COMPLETED',
            ]
        );
        $case->addEvent($event);
        $this->entityManager->persist($event);

        $this->entityManager->flush();

        $this->auditLogger->log(
            event: \App\Enum\AuditAction::DossierStatusChanged,
            success: true,
            user: $this->user(),
            request: $request,
            context: [
                'reference' => $case->getReference(),
                'old_status' => $oldStatus,
                'new_status' => 'COMPLETED',
            ],
            entityType: 'DossierCase',
            entityId: (string) $case->getId()
        );
        $this->addFlash('success', 'Le dossier a été clôturé avec succès.');

        return $this->redirectToRoute('dossier_show', ['id' => $case->getId()]);
    }

    /**
     * Archivage du dossier (Module 9)
     */
    #[Route('/dossiers/{id}/archive', name: 'dossier_archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function archive(DossierCase $case, Request $request): Response
    {
        $user = $this->user();
        if (!$this->access->hasPermission($user, 'DOSSIER_ARCHIVE') && !$this->access->hasPermission($user, 'DOSSIER_VALIDATE')) {
            throw $this->createAccessDeniedException('Permission insuffisante pour archiver ce dossier.');
        }
        $this->access->assertDossierAccess($user, $case);

        if (!$this->isCsrfTokenValid('dossier_archive_'.$case->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $oldStatus = $case->getStatusCode();
        $case->archive($this->user());

        $event = new CaseEvent(
            $case,
            CaseEventType::CaseArchived,
            'Archivage officiel du dossier',
            $this->user(),
            'Dossier archivé et versé aux archives ministérielles.',
            action: 'ARCHIVED',
            oldValue: $oldStatus,
            newValue: 'ARCHIVED',
            organizationUnit: $this->user()->getOrganizationUnit(),
            metadata: [
                'action' => 'ARCHIVED',
            ]
        );
        $case->addEvent($event);
        $this->entityManager->persist($event);

        $this->entityManager->flush();

        $this->auditLogger->log(
            event: \App\Enum\AuditAction::DossierArchived,
            success: true,
            user: $this->user(),
            request: $request,
            context: [
                'reference' => $case->getReference(),
                'old_status' => $oldStatus,
                'new_status' => 'ARCHIVED',
                'documents_count' => $case->getDocuments()->count(),
            ],
            entityType: 'DossierCase',
            entityId: (string) $case->getId()
        );

        foreach ($case->getDocuments() as $archivedDoc) {
            $this->auditLogger->log(
                event: \App\Enum\AuditAction::DocumentArchived,
                success: true,
                user: $this->user(),
                request: $request,
                context: [
                    'case_id' => $case->getId(),
                    'case_reference' => $case->getReference(),
                    'document_id' => $archivedDoc->getId(),
                    'original_name' => $archivedDoc->getOriginalName(),
                    'version' => $archivedDoc->getVersion(),
                ],
                entityType: 'Document',
                entityId: (string) $archivedDoc->getId()
            );
        }

        $this->addFlash('success', 'Le dossier a été versé aux archives.');

        return $this->redirectToRoute('dossier_show', ['id' => $case->getId()]);
    }

    /**
     * Ajout d'une note ou observation interne au dossier (Module 9 - ANNOTATED)
     */
    #[Route('/dossiers/{id}/note', name: 'dossier_add_note', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addNote(DossierCase $case, Request $request): Response
    {
        $user = $this->user();
        if (!$this->access->hasPermission($user, 'DOSSIER_ANNOTATE') && !$this->access->hasPermission($user, 'DOSSIER_EDIT')) {
            throw $this->createAccessDeniedException('Permission insuffisante pour annoter ce dossier.');
        }
        $this->access->assertDossierAccess($user, $case);

        if (!$this->isCsrfTokenValid('dossier_note_'.$case->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $note = trim((string) $request->request->get('note'));
        if ($note === '') {
            $this->addFlash('error', 'Le texte de la note ne peut pas être vide.');
            return $this->redirectToRoute('dossier_show', ['id' => $case->getId(), 'tab' => 'timeline']);
        }

        $event = new CaseEvent(
            $case,
            CaseEventType::NoteAdded,
            'Annotation / Note administrative',
            $this->user(),
            $note,
            action: 'ANNOTATED',
            oldValue: null,
            newValue: mb_substr($note, 0, 100),
            organizationUnit: $this->user()->getOrganizationUnit(),
            metadata: [
                'action' => 'ANNOTATED',
                'length' => mb_strlen($note),
            ]
        );
        $case->addEvent($event);

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        $this->auditLogger->log(
            event: \App\Enum\AuditAction::DossierUpdated,
            success: true,
            user: $this->user(),
            request: $request,
            context: [
                'reference' => $case->getReference(),
                'operation' => 'note_added',
                'note_length' => mb_strlen($note),
            ],
            entityType: 'DossierCase',
            entityId: (string) $case->getId()
        );

        $this->addFlash('success', 'Votre note a été ajoutée à la chronologie du dossier.');

        return $this->redirectToRoute('dossier_show', ['id' => $case->getId(), 'tab' => 'timeline']);
    }

    /**
     * Téléversement d'un document complémentaire rattaché au dossier (Module 9 - DOCUMENT_ADDED)
     */
    #[Route('/dossiers/{id}/documents/upload', name: 'dossier_document_upload', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function uploadDocument(DossierCase $case, Request $request): Response
    {
        $user = $this->user();
        if (!$this->access->hasPermission($user, 'DOCUMENT_UPLOAD') && !$this->access->hasPermission($user, 'DOCUMENT_CREATE')) {
            throw $this->createAccessDeniedException('Permission insuffisante pour téléverser un document.');
        }
        $this->access->assertDossierAccess($user, $case);

        if (!$this->isCsrfTokenValid('dossier_doc_upload_'.$case->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        /** @var UploadedFile|null $uploadedFile */
        $uploadedFile = $request->files->get('document_file');
        if (!$uploadedFile) {
            $this->addFlash('error', 'Aucun fichier sélectionné.');
            return $this->redirectToRoute('dossier_show', ['id' => $case->getId(), 'tab' => 'documents']);
        }

        $docType = trim((string) $request->request->get('document_type', 'PIECE_JOINTE'));

        try {
            $maxExistingVersion = 0;
            $incomingName = strtolower(trim($uploadedFile->getClientOriginalName()));
            foreach ($case->getDocuments() as $existingDoc) {
                if (strtolower(trim($existingDoc->getOriginalName())) === $incomingName) {
                    $maxExistingVersion = max($maxExistingVersion, $existingDoc->getVersion());
                }
            }

            $document = $this->documentUploader->upload($uploadedFile, $docType, $this->user());
            if ($maxExistingVersion > 0) {
                $document->setVersion($maxExistingVersion + 1);
            }
            $case->addDocument($document);

            // Événement document ajouté (DOCUMENT_ADDED - Module 9)
            $event = new CaseEvent(
                $case,
                CaseEventType::DocumentAdded,
                sprintf('Document ajouté : %s', $document->getOriginalName()),
                $this->user(),
                sprintf('Type : %s (Taille : %d Ko)', $docType, (int) round($document->getSize() / 1024)),
                action: 'DOCUMENT_ADDED',
                oldValue: null,
                newValue: $document->getOriginalName(),
                organizationUnit: $this->user()->getOrganizationUnit(),
                metadata: [
                    'action' => 'DOCUMENT_ADDED',
                    'document_id' => $document->getId(),
                    'document_name' => $document->getOriginalName(),
                    'type' => $docType,
                    'size' => $document->getSize(),
                    'version' => $document->getVersion(),
                ]
            );
            $case->addEvent($event);

            $this->entityManager->persist($document);
            $this->entityManager->persist($event);
            $this->entityManager->flush();

            // Audit technique de sécurité (Module 10)
            $this->auditLogger->log(
                event: \App\Enum\AuditAction::DocumentUploaded,
                success: true,
                user: $this->user(),
                request: $request,
                context: [
                    'case_id' => $case->getId(),
                    'case_reference' => $case->getReference(),
                    'document_id' => $document->getId(),
                    'original_name' => $document->getOriginalName(),
                    'mime_type' => $document->getMimeType(),
                    'size' => $document->getSize(),
                    'type' => $docType,
                    'version' => $document->getVersion(),
                ],
                entityType: 'Document',
                entityId: (string) $document->getId()
            );

            if ($document->getVersion() > 1) {
                $this->auditLogger->log(
                    event: \App\Enum\AuditAction::DocumentVersionCreated,
                    success: true,
                    user: $this->user(),
                    request: $request,
                    context: [
                        'case_id' => $case->getId(),
                        'case_reference' => $case->getReference(),
                        'document_id' => $document->getId(),
                        'original_name' => $document->getOriginalName(),
                        'previous_version' => $maxExistingVersion,
                        'new_version' => $document->getVersion(),
                    ],
                    entityType: 'Document',
                    entityId: (string) $document->getId()
                );
            }

            $this->addFlash('success', sprintf('Le document "%s" a été ajouté au dossier.', $document->getOriginalName()));
        } catch (\Throwable $e) {
            $this->auditLogger->log(
                event: \App\Enum\AuditAction::DocumentUploaded,
                success: false,
                user: $this->user(),
                request: $request,
                context: [
                    'case_id' => $case->getId(),
                    'case_reference' => $case->getReference(),
                    'reason' => $e->getMessage(),
                ],
                entityType: 'DossierCase',
                entityId: (string) $case->getId(),
                result: \App\Enum\AuditResult::Failure
            );
            $this->addFlash('error', 'Erreur lors du téléversement : ' . $e->getMessage());
        }

        return $this->redirectToRoute('dossier_show', ['id' => $case->getId(), 'tab' => 'documents']);
    }

    /**
     * Changement explicite du statut opérationnel d'un dossier (S1.08 / S5.04)
     */
    #[Route('/dossiers/{id}/status', name: 'dossier_change_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function changeStatus(DossierCase $case, Request $request): Response
    {
        $user = $this->user();
        if (!$this->access->hasPermission($user, 'DOSSIER_TREAT') && !$this->access->hasPermission($user, 'DOSSIER_EDIT') && !$this->access->hasPermission($user, 'DOSSIER_VALIDATE')) {
            throw $this->createAccessDeniedException('Permission insuffisante pour modifier le statut de ce dossier.');
        }
        $this->access->assertDossierAccess($user, $case);

        if (!$this->isCsrfTokenValid('dossier_status_'.$case->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if ($case->isClosed() || $case->isArchived()) {
            $this->addFlash('error', 'Impossible de modifier le statut d\'un dossier clôturé ou archivé.');
            return $this->redirectToRoute('dossier_show', ['id' => $case->getId()]);
        }

        $newStatus = strtoupper(trim((string) $request->request->get('status_code')));
        $comment = trim((string) $request->request->get('comment'));
        if ($newStatus === '') {
            $this->addFlash('error', 'Le nouveau statut est obligatoire.');
            return $this->redirectToRoute('dossier_show', ['id' => $case->getId()]);
        }

        $oldStatus = $case->getStatusCode();
        $case->setStatusCode($newStatus);

        $event = new CaseEvent(
            $case,
            CaseEventType::CaseUpdated,
            sprintf('Changement de statut : %s → %s', $oldStatus, $newStatus),
            $user,
            $comment !== '' ? $comment : null,
            action: 'STATUS_CHANGED',
            oldValue: $oldStatus,
            newValue: $newStatus,
            organizationUnit: $user->getOrganizationUnit(),
            metadata: [
                'action' => 'STATUS_CHANGED',
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'comment' => $comment !== '' ? $comment : null,
            ]
        );
        $case->addEvent($event);
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        $this->auditLogger->log(
            event: \App\Enum\AuditAction::DossierStatusChanged,
            success: true,
            user: $user,
            request: $request,
            context: [
                'reference' => $case->getReference(),
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'comment' => $comment !== '' ? $comment : null,
            ],
            entityType: 'DossierCase',
            entityId: (string) $case->getId()
        );

        $this->addFlash('success', sprintf('Statut du dossier mis à jour (%s).', $newStatus));

        return $this->redirectToRoute('dossier_show', ['id' => $case->getId()]);
    }

    /**
     * Modification justifiée de l'échéance d'un dossier (S3.10)
     */
    #[Route('/dossiers/{id}/deadline', name: 'dossier_update_deadline', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateDeadline(DossierCase $case, Request $request): Response
    {
        $user = $this->user();
        if (!$this->access->hasPermission($user, 'DOSSIER_ASSIGN') && !$this->access->hasPermission($user, 'DOSSIER_EDIT') && !$this->access->hasPermission($user, 'DOSSIER_VALIDATE')) {
            throw $this->createAccessDeniedException('Permission insuffisante pour modifier l\'échéance de ce dossier.');
        }
        $this->access->assertDossierAccess($user, $case);

        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('dossier_deadline_'.$case->getId(), $token) && !$this->isCsrfTokenValid('update_deadline_'.$case->getId(), $token)) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if ($case->isClosed() || $case->isArchived()) {
            $this->addFlash('error', 'Impossible de modifier l\'échéance d\'un dossier clôturé ou archivé.');
            return $this->redirectToRoute('dossier_show', ['id' => $case->getId()]);
        }

        $deadlineStr = trim((string) $request->request->get('deadline'));
        $justification = trim((string) $request->request->get('justification'));
        if ($justification === '') {
            $justification = 'Ajustement de l\'échéance réglementaire';
        }

        if ($deadlineStr === '') {
            $this->addFlash('error', 'La nouvelle date d\'échéance est obligatoire.');
            return $this->redirectToRoute('dossier_show', ['id' => $case->getId()]);
        }

        try {
            $newDeadline = new \DateTimeImmutable($deadlineStr . ' 23:59:59');
        } catch (\Throwable) {
            $this->addFlash('error', 'Format de date d\'échéance invalide.');
            return $this->redirectToRoute('dossier_show', ['id' => $case->getId()]);
        }

        $oldDeadline = $case->getDeadline()?->format('Y-m-d') ?? 'Non définie';
        $case->setDeadline($newDeadline);

        $activeTask = $case->getWorkflowInstance()?->getActiveTask();
        if ($activeTask !== null) {
            $activeTask->setDeadline($newDeadline);
        }

        $event = new CaseEvent(
            $case,
            CaseEventType::CaseUpdated,
            sprintf('Modification de l\'échéance : %s → %s', $oldDeadline, $newDeadline->format('d/m/Y')),
            $user,
            $justification,
            action: 'DEADLINE_UPDATED',
            oldValue: $oldDeadline,
            newValue: $newDeadline->format('Y-m-d'),
            organizationUnit: $user->getOrganizationUnit(),
            metadata: [
                'action' => 'DEADLINE_UPDATED',
                'old_deadline' => $oldDeadline,
                'new_deadline' => $newDeadline->format('Y-m-d'),
                'justification' => $justification,
            ]
        );
        $case->addEvent($event);
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        $this->auditLogger->log(
            event: \App\Enum\AuditAction::DossierUpdated,
            success: true,
            user: $user,
            request: $request,
            context: [
                'reference' => $case->getReference(),
                'operation' => 'deadline_updated',
                'old_deadline' => $oldDeadline,
                'new_deadline' => $newDeadline->format('Y-m-d'),
                'justification' => $justification,
            ],
            entityType: 'DossierCase',
            entityId: (string) $case->getId()
        );

        $this->addFlash('success', sprintf('L\'échéance du dossier a été fixée au %s.', $newDeadline->format('d/m/Y')));

        return $this->redirectToRoute('dossier_show', ['id' => $case->getId(), 'tab' => 'timeline']);
    }
}
