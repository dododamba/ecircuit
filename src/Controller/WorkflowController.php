<?php

namespace App\Controller;

use App\Entity\DossierCase;
use App\Entity\OrganizationUnit;
use App\Entity\User;
use App\Enum\WorkflowAction;
use App\Exception\InvalidWorkflowTransitionException;
use App\Exception\WorkflowAuthorizationException;
use App\Exception\WorkflowException;
use App\Repository\OrganizationUnitRepository;
use App\Repository\UserRepository;
use App\Service\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/dossiers/{id}/workflow', requirements: ['id' => '\d+'])]
class WorkflowController extends AbstractController
{
    public function __construct(
        private readonly WorkflowService $workflowService,
        private readonly EntityManagerInterface $entityManager,
        private readonly OrganizationUnitRepository $organizationUnitRepository,
        private readonly UserRepository $userRepository,
        private readonly \App\Service\AuditLogger $auditLogger,
        private readonly \App\Service\AccessScopeService $accessScopeService
    ) {}

    private function user(): User
    {
        /** @var User $user */
        $user = $this->getUser();
        return $user;
    }

    /**
     * Exécution d'une action de workflow sur un dossier
     */
    #[Route('/action', name: 'dossier_workflow_action', methods: ['POST'])]
    public function executeAction(DossierCase $case, Request $request): Response
    {
        $isJson = $request->getContentTypeFormat() === 'json' || $request->isXmlHttpRequest();

        // 1. Validation du jeton CSRF
        $token = (string) ($request->request->get('_token') ?? $request->headers->get('X-CSRF-TOKEN'));
        if (!$this->isCsrfTokenValid('workflow_action_' . $case->getId(), $token)) {
            if ($isJson) {
                return new JsonResponse(['success' => false, 'error' => 'Jeton de sécurité CSRF invalide.'], Response::HTTP_FORBIDDEN);
            }
            $this->addFlash('error', 'Session ou jeton de sécurité expiré. Veuillez réessayer.');
            return $this->redirectToRoute('dossier_show', ['id' => $case->getId(), 'tab' => 'workflow']);
        }

        // 2. Détermination de l'action
        $actionName = trim((string) $request->request->get('action'));
        $action = WorkflowAction::tryFrom($actionName);

        if (!$action) {
            $errorMsg = sprintf('Action de workflow inconnue ou non prise en charge : "%s".', $actionName);
            if ($isJson) {
                return new JsonResponse(['success' => false, 'error' => $errorMsg], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', $errorMsg);
            return $this->redirectToRoute('dossier_show', ['id' => $case->getId(), 'tab' => 'workflow']);
        }

        // 3. Extraction des paramètres
        $comment = trim((string) $request->request->get('comment'));
        $targetStructureId = $request->request->getInt('target_structure_id') ?: null;
        $assigneeId = $request->request->getInt('assignee_id') ?: null;

        $targetStructure = $targetStructureId ? $this->organizationUnitRepository->find($targetStructureId) : null;
        $assignee = $assigneeId ? $this->userRepository->find($assigneeId) : null;

        $metadata = [];
        $priority = $request->request->get('priority');
        if ($priority) {
            $metadata['priority'] = $priority;
        }
        $instruction = $request->request->get('instruction');
        if ($instruction) {
            $metadata['instruction'] = $instruction;
        }
        $deadline = $request->request->get('deadline');
        if ($deadline) {
            $metadata['deadline'] = $deadline;
        }

        try {
            $transition = $this->workflowService->executeAction(
                case: $case,
                action: $action,
                user: $this->user(),
                comment: $comment !== '' ? $comment : null,
                targetStructure: $targetStructure,
                assignee: $assignee,
                metadata: !empty($metadata) ? $metadata : null
            );

            $currentStepName = $case->getWorkflowInstance()?->getCurrentStep()->getName() ?? 'N/A';
            $successMsg = sprintf(
                'Action "%s" exécutée avec succès. Le dossier est désormais à l\'étape : %s.',
                $action->label(),
                $currentStepName
            );

            if ($isJson) {
                return new JsonResponse([
                    'success' => true,
                    'message' => $successMsg,
                    'current_step' => [
                        'code' => $case->getWorkflowInstance()?->getCurrentStep()->getCode(),
                        'name' => $currentStepName,
                    ],
                    'case_status' => $case->getStatusCode(),
                ]);
            }

            $this->addFlash('success', $successMsg);
            return $this->redirectToRoute('dossier_show', ['id' => $case->getId(), 'tab' => 'workflow']);

        } catch (WorkflowAuthorizationException $e) {
            $auditAction = $this->mapWorkflowActionToAuditAction($action);
            $this->auditLogger->log(
                event: $auditAction,
                success: false,
                user: $this->user(),
                request: $request,
                context: [
                    'case_reference' => $case->getReference(),
                    'workflow_action' => $action->value,
                    'reason' => $e->getMessage(),
                ],
                entityType: 'DossierCase',
                entityId: (string) $case->getId(),
                result: \App\Enum\AuditResult::Denied,
                statusCode: Response::HTTP_FORBIDDEN
            );

            if ($isJson) {
                return new JsonResponse(['success' => false, 'error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
            }
            $this->addFlash('error', 'Habilitation insuffisante : ' . $e->getMessage());
            return $this->redirectToRoute('dossier_show', ['id' => $case->getId(), 'tab' => 'workflow']);

        } catch (InvalidWorkflowTransitionException $e) {
            $auditAction = $this->mapWorkflowActionToAuditAction($action);
            $this->auditLogger->log(
                event: $auditAction,
                success: false,
                user: $this->user(),
                request: $request,
                context: [
                    'case_reference' => $case->getReference(),
                    'workflow_action' => $action->value,
                    'reason' => $e->getMessage(),
                ],
                entityType: 'DossierCase',
                entityId: (string) $case->getId(),
                result: \App\Enum\AuditResult::Failure,
                statusCode: Response::HTTP_BAD_REQUEST
            );

            if ($isJson) {
                return new JsonResponse(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Transition non autorisée : ' . $e->getMessage());
            return $this->redirectToRoute('dossier_show', ['id' => $case->getId(), 'tab' => 'workflow']);

        } catch (WorkflowException $e) {
            $auditAction = $this->mapWorkflowActionToAuditAction($action);
            $this->auditLogger->log(
                event: $auditAction,
                success: false,
                user: $this->user(),
                request: $request,
                context: [
                    'case_reference' => $case->getReference(),
                    'workflow_action' => $action->value,
                    'reason' => $e->getMessage(),
                ],
                entityType: 'DossierCase',
                entityId: (string) $case->getId(),
                result: \App\Enum\AuditResult::Failure,
                statusCode: Response::HTTP_BAD_REQUEST
            );

            if ($isJson) {
                return new JsonResponse(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Erreur de workflow : ' . $e->getMessage());
            return $this->redirectToRoute('dossier_show', ['id' => $case->getId(), 'tab' => 'workflow']);
        }
    }

    private function mapWorkflowActionToAuditAction(WorkflowAction $action): \App\Enum\AuditAction
    {
        return match ($action) {
            WorkflowAction::Submit       => \App\Enum\AuditAction::WorkflowSubmitted,
            WorkflowAction::Annotate     => \App\Enum\AuditAction::WorkflowAnnotated,
            WorkflowAction::Orient       => \App\Enum\AuditAction::WorkflowOriented,
            WorkflowAction::Assign       => \App\Enum\AuditAction::WorkflowAssigned,
            WorkflowAction::Forward      => \App\Enum\AuditAction::WorkflowForwarded,
            WorkflowAction::Return       => \App\Enum\AuditAction::WorkflowReturned,
            WorkflowAction::Reject       => \App\Enum\AuditAction::WorkflowRejected,
            WorkflowAction::Validate     => \App\Enum\AuditAction::WorkflowValidated,
            WorkflowAction::Close,
            WorkflowAction::Release      => \App\Enum\AuditAction::WorkflowCompleted,
            WorkflowAction::TakeInCharge,
            WorkflowAction::Process      => \App\Enum\AuditAction::DossierStatusChanged,
        };
    }

    /**
     * État actuel et transitions disponibles au format JSON (pour Stimulus / visualiseur)
     */
    #[Route('/status', name: 'dossier_workflow_status', methods: ['GET'])]
    public function status(DossierCase $case): JsonResponse
    {
        $this->accessScopeService->assertDossierAccess($this->user(), $case);
        $instance = $this->workflowService->start($case);
        $transitions = $this->workflowService->getAvailableTransitions($case, $this->user());

        $availableActions = array_map(fn($t) => [
            'action' => $t->getAction()->value,
            'label' => $t->getAction()->label(),
            'icon' => $t->getAction()->icon(),
            'badge_class' => $t->getAction()->badgeClass(),
            'target_step' => [
                'code' => $t->getTargetStep()->getCode(),
                'name' => $t->getTargetStep()->getName(),
            ],
            'comment_required' => $t->isCommentRequired(),
            'is_return' => $t->isReturn(),
        ], $transitions);

        return new JsonResponse([
            'case_id' => $case->getId(),
            'reference' => $case->getReference(),
            'status' => $case->getStatusCode(),
            'workflow' => [
                'code' => $instance->getWorkflow()->getCode(),
                'name' => $instance->getWorkflow()->getName(),
            ],
            'current_step' => [
                'code' => $instance->getCurrentStep()->getCode(),
                'name' => $instance->getCurrentStep()->getName(),
                'order' => $instance->getCurrentStep()->getOrder(),
                'structure' => $instance->getCurrentStep()->getTargetStructure()?->getName(),
                'role' => $instance->getCurrentStep()->getTargetRole(),
            ],
            'current_structure' => $case->getCurrentStructure()?->getName(),
            'current_assignee' => $case->getCurrentAssignee()?->getFullName(),
            'available_actions' => $availableActions,
        ]);
    }
}
