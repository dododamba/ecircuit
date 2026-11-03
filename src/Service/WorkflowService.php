<?php

namespace App\Service;

use App\Entity\AdministrativeCircuit;
use App\Entity\CaseEvent;
use App\Entity\CaseTransmission;
use App\Entity\DossierCase;
use App\Entity\OrganizationUnit;
use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowHistory;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Entity\WorkflowTask;
use App\Entity\WorkflowTransition;
use App\Enum\CaseEventType;
use App\Enum\EntryChannel;
use App\Enum\StructureType;
use App\Enum\WorkflowAction;
use App\Enum\WorkflowInstanceStatus;
use App\Enum\WorkflowTaskStatus;
use App\Exception\InvalidWorkflowTransitionException;
use App\Exception\WorkflowAuthorizationException;
use App\Exception\WorkflowException;
use App\Repository\WorkflowInstanceRepository;
use App\Repository\WorkflowRepository;
use App\Repository\WorkflowTransitionRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class WorkflowService
{
    public const DEFAULT_WORKFLOW_CODE = 'WORKFLOW_CABINET_STANDARD';
    public const SG_WORKFLOW_CODE = 'WORKFLOW_SG_STANDARD';
    public const PROVINCIAL_WORKFLOW_CODE = 'WORKFLOW_PROVINCIAL';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowRepository $workflowRepository,
        private readonly WorkflowInstanceRepository $instanceRepository,
        private readonly WorkflowTransitionRepository $transitionRepository,
        private readonly AccessScopeService $accessScope,
        private readonly AuditLogger $auditLogger,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?NotificationService $notificationService = null,
        private readonly ?CircuitWorkflowSyncService $circuitSyncService = null
    ) {}

    /**
     * Démarre une instance de workflow pour un dossier donné (S3.03, S3.11, S3.12)
     */
    public function start(DossierCase $case, ?Workflow $workflow = null, ?User $startedBy = null): WorkflowInstance
    {
        if ($case->getWorkflowInstance() !== null) {
            return $case->getWorkflowInstance();
        }

        $wf = $workflow;

        // 1. Si un circuit administratif spécifique est associé au dossier (S3.11)
        if ($wf === null && $case->getCircuit() !== null && $this->circuitSyncService !== null) {
            $wf = $this->circuitSyncService->resolveWorkflowForCircuit($case->getCircuit());
        }

        // 2. Détection Délégation Provinciale (S3.12)
        $initialStructure = $case->getCurrentStructure() ?? $startedBy?->getOrganizationUnit();
        $isProvincial = $initialStructure !== null && $initialStructure->getType() === StructureType::DelegationProvinciale;
        if ($wf === null && $isProvincial) {
            $wf = $this->workflowRepository->findActiveByCode(self::PROVINCIAL_WORKFLOW_CODE);
            if ($case->getCircuit() === null) {
                $provCircuit = $this->entityManager->getRepository(AdministrativeCircuit::class)->findOneBy(['code' => 'CIRCUIT_PROVINCIAL']);
                if ($provCircuit !== null) {
                    $case->setCircuit($provCircuit);
                }
            }
        }

        // 3. Détection Canal Secrétariat Général direct (S3.03)
        if ($wf === null && $case->getEntryChannel() === EntryChannel::SecretariatGeneral) {
            $wf = $this->workflowRepository->findActiveByCode(self::SG_WORKFLOW_CODE);
            if ($case->getCircuit() === null) {
                $sgCircuit = $this->entityManager->getRepository(AdministrativeCircuit::class)->findOneBy(['code' => 'CIRCUIT_SG_STANDARD']);
                if ($sgCircuit !== null) {
                    $case->setCircuit($sgCircuit);
                }
            }
        }

        // 4. Repli sur le circuit Cabinet standard
        if ($wf === null) {
            $wf = $this->workflowRepository->findActiveByCode(self::DEFAULT_WORKFLOW_CODE);
            if ($case->getCircuit() === null) {
                $cabCircuit = $this->entityManager->getRepository(AdministrativeCircuit::class)->findOneBy(['code' => 'CIRCUIT_CABINET_STANDARD']);
                if ($cabCircuit !== null) {
                    $case->setCircuit($cabCircuit);
                }
            }
        }

        if (!$wf) {
            // Recherche par le premier workflow actif disponible
            $wf = $this->workflowRepository->findOneBy(['active' => true]);
        }

        if (!$wf) {
            throw new WorkflowException('Aucun schéma de workflow actif n\'est configuré dans le système.');
        }

        $initialStep = $wf->getInitialStep();
        if (!$initialStep) {
            throw new WorkflowException(sprintf('Le workflow "%s" ne possède aucune étape initiale valide.', $wf->getName()));
        }

        $instance = new WorkflowInstance($case, $wf, $initialStep);
        $case->setWorkflowInstance($instance);

        if ($initialStep->getTargetStructure() !== null && $case->getCurrentStructure() === null) {
            $case->setCurrentStructure($initialStep->getTargetStructure());
        }

        // Création de la tâche initiale
        $task = new WorkflowTask(
            $instance,
            $initialStep,
            $case->getCurrentStructure(),
            $case->getCurrentAssignee(),
            'Prise en charge et enregistrement initial du dossier'
        );

        $this->entityManager->persist($instance);
        $this->entityManager->persist($task);

        if ($startedBy !== null) {
            $history = new WorkflowHistory(
                $instance,
                null,
                $initialStep,
                WorkflowAction::Submit,
                $startedBy,
                $case->getCurrentStructure(),
                'Démarrage du circuit administratif standard'
            );
            $this->entityManager->persist($history);
        }

        $this->entityManager->flush();

        return $instance;
    }

    /**
     * Retourne la liste des transitions permises pour le dossier et l'utilisateur connecté
     *
     * @return list<WorkflowTransition>
     */
    public function getAvailableTransitions(DossierCase $case, User $user): array
    {
        $instance = $case->getWorkflowInstance();
        if (!$instance || $instance->isCompleted() || $case->isClosed() || $case->isArchived()) {
            return [];
        }

        $transitions = $this->transitionRepository->findBySourceStep($instance->getCurrentStep());
        $available = [];

        foreach ($transitions as $transition) {
            if ($this->isTransitionAuthorized($case, $transition, $user)) {
                $available[] = $transition;
            }
        }

        return $available;
    }

    /**
     * Vérifie si une action donnée peut être exécutée sur le dossier par l'utilisateur
     */
    public function canExecute(DossierCase $case, WorkflowAction|string $action, User $user): bool
    {
        $actionEnum = is_string($action) ? WorkflowAction::tryFrom(strtoupper(trim($action))) : $action;
        if (!$actionEnum) {
            return false;
        }

        $transitions = $this->getAvailableTransitions($case, $user);
        foreach ($transitions as $transition) {
            if ($transition->getAction() === $actionEnum) {
                return true;
            }
        }

        return false;
    }

    /**
     * Valide et résout la transition autorisée correspondant à l'action demandée
     */
    public function assertCanExecute(DossierCase $case, WorkflowAction|string $action, User $user): WorkflowTransition
    {
        $actionEnum = is_string($action) ? WorkflowAction::tryFrom(strtoupper(trim($action))) : $action;
        if (!$actionEnum) {
            throw new InvalidWorkflowTransitionException(sprintf('Action de workflow inconnue : "%s".', (string) $action));
        }

        if ($case->isClosed() || $case->isArchived()) {
            throw new InvalidWorkflowTransitionException(sprintf('Le dossier %s est déjà clôturé ou archivé.', $case->getReference()));
        }

        $instance = $case->getWorkflowInstance();
        if (!$instance) {
            throw new WorkflowException(sprintf('Aucune instance de workflow active pour le dossier %s.', $case->getReference()));
        }

        if ($instance->isCompleted()) {
            throw new InvalidWorkflowTransitionException(sprintf('Le cycle de workflow du dossier %s est déjà achevé.', $case->getReference()));
        }

        $transitions = $this->transitionRepository->findBySourceStep($instance->getCurrentStep());
        $matchedTransition = null;

        foreach ($transitions as $t) {
            if ($t->getAction() === $actionEnum) {
                $matchedTransition = $t;
                break;
            }
        }

        if (!$matchedTransition) {
            throw new InvalidWorkflowTransitionException(sprintf(
                'L\'action "%s" n\'est pas autorisée depuis l\'étape actuelle "%s".',
                $actionEnum->label(),
                $instance->getCurrentStep()->getName()
            ));
        }

        // Vérification des autorisations de l'utilisateur
        if (!$this->isTransitionAuthorized($case, $matchedTransition, $user)) {
            throw new WorkflowAuthorizationException(sprintf(
                'Vous n\'avez pas les habilitations requises pour exécuter l\'action "%s" sur ce dossier.',
                $actionEnum->label()
            ));
        }

        return $matchedTransition;
    }

    /**
     * Exécute une action de transition au sein d'une transaction Doctrine atomique
     */
    public function executeAction(
        DossierCase $case,
        WorkflowAction|string $action,
        User $user,
        ?string $comment = null,
        ?OrganizationUnit $targetStructure = null,
        ?User $assignee = null,
        ?array $metadata = null
    ): WorkflowHistory {
        $actionEnum = is_string($action) ? WorkflowAction::tryFrom(strtoupper(trim($action))) : $action;
        if (!$actionEnum) {
            throw new InvalidWorkflowTransitionException(sprintf('Action de workflow inconnue : "%s".', (string) $action));
        }

        // 1. Validation préalable de l'instance et de la transition
        $transition = $this->assertCanExecute($case, $actionEnum, $user);
        $instance = $case->getWorkflowInstance();
        $sourceStep = $instance->getCurrentStep();
        $targetStep = $transition->getTargetStep();

        // 2. Application de la RÈGLE ABSOLUE RB-005 (Pas de contournement du SG par le Ministre)
        $this->applyRuleRb005Check($sourceStep, $targetStep, $targetStructure);

        // 3. Validation des conditions spécifiques
        $commentTrimmed = $comment !== null ? trim($comment) : null;
        if ($actionEnum === WorkflowAction::Reject && empty($commentTrimmed)) {
            throw new InvalidWorkflowTransitionException('Un motif de rejet formel est obligatoirement requis.');
        }
        if ($transition->isCommentRequired() && empty($commentTrimmed)) {
            throw new InvalidWorkflowTransitionException(sprintf('Un commentaire ou motif est obligatoire pour l\'action "%s".', $actionEnum->label()));
        }

        return $this->entityManager->wrapInTransaction(function () use (
            $case, $actionEnum, $user, $commentTrimmed, $targetStructure, $assignee, $metadata, $instance, $sourceStep, $targetStep
        ): WorkflowHistory {
            // 4. Calcul de la durée passée dans l'étape précédente
            $durationSeconds = (new \DateTimeImmutable())->getTimestamp() - $instance->getStepEnteredAt()->getTimestamp();

            // 5. Clôture de la tâche active précédente
            $activeTask = $instance->getActiveTask();
            if ($activeTask !== null) {
                if ($actionEnum === WorkflowAction::Reject) {
                    $activeTask->cancel($commentTrimmed);
                } else {
                    $activeTask->complete($user, $commentTrimmed);
                }
            }

            // 6. Changement d'étape dans l'instance de workflow
            $instance->setCurrentStep($targetStep);

            // 7. Détermination de la nouvelle structure détentrice et de l'assignataire
            $isProvincialWorkflow = $instance->getWorkflow()->getCode() === self::PROVINCIAL_WORKFLOW_CODE
                && $case->getCurrentStructure()?->getType() === StructureType::DelegationProvinciale;

            $newStructure = $targetStructure
                ?? ($isProvincialWorkflow ? $case->getCurrentStructure() : $targetStep->getTargetStructure())
                ?? ($assignee?->getOrganizationUnit())
                ?? $case->getCurrentStructure();

            $newAssignee = $assignee;
            if ($actionEnum === WorkflowAction::TakeInCharge) {
                $newAssignee = $user;
            } elseif ($targetStructure !== null && $assignee === null) {
                // Si le dossier passe à une nouvelle structure sans assignataire individuel explicite, on remet l'assignataire à null
                $newAssignee = null;
            }

            $sourceStructure = $case->getCurrentStructure();
            $case->setCurrentStructure($newStructure);
            $case->setCurrentAssignee($newAssignee);

            // Gestion de l'échéance si fournie dans les métadonnées
            if (!empty($metadata['deadline'])) {
                try {
                    $dl = $metadata['deadline'] instanceof \DateTimeInterface
                        ? \DateTimeImmutable::createFromInterface($metadata['deadline'])
                        : new \DateTimeImmutable((string) $metadata['deadline']);
                    $case->setDeadline($dl);
                } catch (\Throwable) {}
            }

            // 8. Mise à jour du statut fonctionnel du dossier (DossierCase::$statusCode)
            $previousStatusCode = $case->getStatusCode();
            $this->updateCaseStatusForAction($case, $actionEnum, $targetStep, $user);

            // 9. Création de la nouvelle tâche (WorkflowTask)
            $newTask = null;
            if (!$targetStep->isFinal()) {
                $newTask = new WorkflowTask(
                    $instance,
                    $targetStep,
                    $newStructure,
                    $newAssignee,
                    $commentTrimmed,
                    $targetStep->getStandardDurationHours()
                );

                if ($case->getDeadline() !== null) {
                    $newTask->setDeadline($case->getDeadline());
                }

                if ($actionEnum === WorkflowAction::TakeInCharge) {
                    $newTask->start();
                }

                $this->entityManager->persist($newTask);
            } else {
                $instance->markCompleted();
            }

            // 10. Traçabilité immuable du workflow (WorkflowHistory)
            $history = new WorkflowHistory(
                $instance,
                $sourceStep,
                $targetStep,
                $actionEnum,
                $user,
                $user->getOrganizationUnit() ?? $sourceStructure,
                $commentTrimmed,
                $metadata,
                $durationSeconds
            );
            $this->entityManager->persist($history);

            // 11. Transmission inter-services le cas échéant (CaseTransmission)
            if ($newStructure !== null && $sourceStructure !== null && $newStructure->getId() !== $sourceStructure->getId()) {
                $transmission = new CaseTransmission(
                    $case,
                    $sourceStructure,
                    $newStructure,
                    $user,
                    $actionEnum->label() . ($commentTrimmed ? ' : ' . mb_substr($commentTrimmed, 0, 50) : ''),
                    $commentTrimmed
                );
                $case->addTransmission($transmission);
                $this->entityManager->persist($transmission);
            }

            // 12. Événement unifié de traçabilité (CaseEvent - Module 9)
            $actionNorm = match ($actionEnum) {
                WorkflowAction::Submit       => 'SUBMITTED',
                WorkflowAction::Annotate     => 'ANNOTATED',
                WorkflowAction::Orient       => 'ORIENTED',
                WorkflowAction::Forward      => 'FORWARDED',
                WorkflowAction::Assign       => 'ASSIGNED',
                WorkflowAction::TakeInCharge => 'TAKEN_IN_CHARGE',
                WorkflowAction::Process      => 'PROCESSED',
                WorkflowAction::Return       => 'RETURNED',
                WorkflowAction::Validate     => 'VALIDATED',
                WorkflowAction::Reject       => 'REJECTED',
                WorkflowAction::Close        => 'COMPLETED',
                WorkflowAction::Release      => 'RELEASED',
                WorkflowAction::Escalate     => 'WORKFLOW_ESCALATED',
            };

            $eventLabel = sprintf(
                'Workflow [%s] : %s → %s',
                $actionEnum->label(),
                $sourceStep->getName(),
                $targetStep->getName()
            );
            $event = new CaseEvent(
                $case,
                $this->mapActionToCaseEventType($actionEnum),
                $eventLabel,
                $user,
                $commentTrimmed
            );
            $event->setAction($actionNorm);

            // Ancienne et nouvelle valeur contextuelles
            if ($actionEnum === WorkflowAction::Annotate) {
                $event->setOldValue(null);
                $event->setNewValue($commentTrimmed);
            } elseif ($actionEnum === WorkflowAction::Assign) {
                $event->setOldValue($sourceStructure?->getName() ?? 'Non affecté');
                $event->setNewValue($newAssignee?->getFullName() ?? $newStructure?->getName());
            } elseif ($actionEnum === WorkflowAction::Orient || $actionEnum === WorkflowAction::Forward) {
                $event->setOldValue($sourceStructure?->getName());
                $event->setNewValue($newStructure?->getName());
            } elseif ($actionEnum === WorkflowAction::TakeInCharge) {
                $event->setOldValue('En attente de prise en charge');
                $event->setNewValue($user->getFullName());
            } else {
                $event->setOldValue($sourceStep->getName());
                $event->setNewValue($targetStep->getName());
            }

            $event->setOrganizationUnit($user->getOrganizationUnit() ?? $sourceStructure);
            $event->setMetadata([
                'workflow_action' => $actionEnum->value,
                'action_code' => $actionNorm,
                'source_step' => $sourceStep->getCode(),
                'target_step' => $targetStep->getCode(),
                'source_structure' => $sourceStructure?->getName(),
                'target_structure' => $newStructure?->getName(),
                'assignee' => $newAssignee?->getFullName(),
            ]);
            $case->addEvent($event);
            $this->entityManager->persist($event);

            // 13. Audit technique de sécurité (Module 10)
            $auditAction = match ($actionEnum) {
                WorkflowAction::Submit       => \App\Enum\AuditAction::WorkflowSubmitted,
                WorkflowAction::Annotate     => \App\Enum\AuditAction::WorkflowAnnotated,
                WorkflowAction::Orient       => \App\Enum\AuditAction::WorkflowOriented,
                WorkflowAction::Assign       => \App\Enum\AuditAction::WorkflowAssigned,
                WorkflowAction::Forward      => (in_array($sourceStep->getCode(), ['ORIENTATION_SG', 'SG_REVIEW'], true) ? \App\Enum\AuditAction::WorkflowOriented : \App\Enum\AuditAction::WorkflowForwarded),
                WorkflowAction::Return       => \App\Enum\AuditAction::WorkflowReturned,
                WorkflowAction::Reject       => \App\Enum\AuditAction::WorkflowRejected,
                WorkflowAction::Validate     => \App\Enum\AuditAction::WorkflowValidated,
                WorkflowAction::Escalate     => \App\Enum\AuditAction::WorkflowEscalated,
                WorkflowAction::Process,
                WorkflowAction::Close,
                WorkflowAction::Release      => \App\Enum\AuditAction::WorkflowCompleted,
                WorkflowAction::TakeInCharge => \App\Enum\AuditAction::DossierStatusChanged,
            };

            $baseAuditContext = [
                'case_reference' => $case->getReference(),
                'workflow_action' => $actionEnum->value,
                'source_step' => $sourceStep->getCode(),
                'target_step' => $targetStep->getCode(),
                'source_structure' => $sourceStructure?->getCode(),
                'target_structure' => $newStructure?->getCode(),
                'assignee_id' => $newAssignee?->getId(),
            ];
            $caseEntityId = (string) ($case->getId() ?? $case->getReference());

            $this->auditLogger->log(
                event: $auditAction,
                success: true,
                user: $user,
                request: null,
                context: $baseAuditContext,
                entityType: 'DossierCase',
                entityId: $caseEntityId,
                result: \App\Enum\AuditResult::Success,
                flush: false
            );

            if ($previousStatusCode !== $case->getStatusCode() && $auditAction !== \App\Enum\AuditAction::DossierStatusChanged) {
                $this->auditLogger->log(
                    event: \App\Enum\AuditAction::DossierStatusChanged,
                    success: true,
                    user: $user,
                    request: null,
                    context: array_merge($baseAuditContext, [
                        'old_status' => $previousStatusCode,
                        'new_status' => $case->getStatusCode(),
                    ]),
                    entityType: 'DossierCase',
                    entityId: $caseEntityId,
                    result: \App\Enum\AuditResult::Success,
                    flush: false
                );
            }

            if (in_array($actionEnum, [WorkflowAction::Forward, WorkflowAction::Orient], true)) {
                $this->auditLogger->log(
                    event: \App\Enum\AuditAction::DossierForwarded,
                    success: true,
                    user: $user,
                    request: null,
                    context: $baseAuditContext,
                    entityType: 'DossierCase',
                    entityId: $caseEntityId,
                    result: \App\Enum\AuditResult::Success,
                    flush: false
                );
            } elseif ($actionEnum === WorkflowAction::Assign) {
                $this->auditLogger->log(
                    event: \App\Enum\AuditAction::DossierAssigned,
                    success: true,
                    user: $user,
                    request: null,
                    context: $baseAuditContext,
                    entityType: 'DossierCase',
                    entityId: $caseEntityId,
                    result: \App\Enum\AuditResult::Success,
                    flush: false
                );
            }

            // 14. Déclenchement des notifications in-app automatiques
            if ($this->notificationService !== null) {
                $link = '/dossiers/' . $case->getId();
                if ($newAssignee !== null && $newAssignee->getId() !== $user->getId()) {
                    $this->notificationService->notifyUser(
                        $newAssignee,
                        'Dossier affecté : ' . $case->getReference(),
                        sprintf('Le dossier %s vous a été affecté (%s).', $case->getReference(), $targetStep->getName())
                            . ($commentTrimmed ? ' Consigne : ' . $commentTrimmed : ''),
                        'assignment',
                        $link,
                        'ki-user-tick'
                    );
                } elseif ($newStructure !== null && ($sourceStructure === null || $newStructure->getId() !== $sourceStructure->getId())) {
                    $this->notificationService->notifyStructure(
                        $newStructure,
                        'Dossier transmis : ' . $case->getReference(),
                        sprintf('Le dossier %s a été transmis à votre structure (%s).', $case->getReference(), $newStructure->getName()),
                        'transmission',
                        $link,
                        'ki-send',
                        $user
                    );
                } elseif ($actionEnum === WorkflowAction::Return && $sourceStructure !== null) {
                    $this->notificationService->notifyStructure(
                        $sourceStructure,
                        'Dossier retourné : ' . $case->getReference(),
                        sprintf('Le dossier %s a été retourné à l\'étape %s. Motif : %s', $case->getReference(), $targetStep->getName(), $commentTrimmed ?: 'Non précisé'),
                        'return',
                        $link,
                        'ki-arrows-circle',
                        $user
                    );
                }
            }

            $this->entityManager->flush();

            return $history;
        });
    }

    // =========================================================================
    // MÉTHODES MÉTIER DÉDIÉES (RACCOURCIS MÉTIER)
    // =========================================================================

    public function submit(DossierCase $case, User $user, ?string $comment = null): WorkflowHistory
    {
        return $this->executeAction($case, WorkflowAction::Submit, $user, $comment);
    }

    public function annotate(DossierCase $case, User $user, string $annotation): WorkflowHistory
    {
        return $this->executeAction($case, WorkflowAction::Annotate, $user, $annotation);
    }

    public function orient(DossierCase $case, User $user, OrganizationUnit $targetStructure, ?string $comment = null): WorkflowHistory
    {
        return $this->executeAction($case, WorkflowAction::Orient, $user, $comment, $targetStructure);
    }

    public function forward(DossierCase $case, User $user, ?OrganizationUnit $targetStructure = null, ?string $comment = null): WorkflowHistory
    {
        return $this->executeAction($case, WorkflowAction::Forward, $user, $comment, $targetStructure);
    }

    public function assign(
        DossierCase $case,
        User $user,
        ?User $assignee = null,
        ?OrganizationUnit $targetStructure = null,
        ?string $instructions = null,
        ?\DateTimeImmutable $deadline = null
    ): WorkflowHistory {
        $metadata = [];
        if ($deadline !== null) {
            $metadata['deadline'] = $deadline->format('Y-m-d');
        }
        return $this->executeAction($case, WorkflowAction::Assign, $user, $instructions, $targetStructure, $assignee, !empty($metadata) ? $metadata : null);
    }

    public function takeInCharge(DossierCase $case, User $user, ?string $comment = null): WorkflowHistory
    {
        return $this->executeAction($case, WorkflowAction::TakeInCharge, $user, $comment);
    }

    public function process(DossierCase $case, User $user, ?string $comment = null): WorkflowHistory
    {
        return $this->executeAction($case, WorkflowAction::Process, $user, $comment);
    }

    public function return(DossierCase $case, User $user, string $reason, ?string $comment = null, ?OrganizationUnit $targetStructure = null): WorkflowHistory
    {
        $fullComment = $reason . ($comment ? ' — ' . $comment : '');
        return $this->executeAction($case, WorkflowAction::Return, $user, $fullComment, $targetStructure);
    }

    public function validate(DossierCase $case, User $user, ?string $comment = null): WorkflowHistory
    {
        return $this->executeAction($case, WorkflowAction::Validate, $user, $comment);
    }

    public function reject(DossierCase $case, User $user, string $justification): WorkflowHistory
    {
        return $this->executeAction($case, WorkflowAction::Reject, $user, $justification);
    }

    public function close(DossierCase $case, User $user, ?string $comment = null): WorkflowHistory
    {
        return $this->executeAction($case, WorkflowAction::Close, $user, $comment);
    }

    public function release(DossierCase $case, User $user, ?string $comment = null): WorkflowHistory
    {
        return $this->executeAction($case, WorkflowAction::Release, $user, $comment);
    }

    // =========================================================================
    // RÈGLES INTERNES DE SÉCURITÉ ET D'AUTORISATION
    // =========================================================================

    /**
     * Vérifie si un utilisateur est habilité à franchir une transition
     */
    private function isTransitionAuthorized(DossierCase $case, WorkflowTransition $transition, User $user): bool
    {
        // 1. Un administrateur a toujours le droit d'orchestration
        if ($user->hasAccessRole('ADMINISTRATEUR')) {
            return true;
        }

        // 2. Vérification du rôle requis
        if ($transition->getAuthorizedRole() !== null) {
            $requiredRoleCode = $transition->getAuthorizedRole()->getCode();
            if (!$user->hasAccessRole($requiredRoleCode)) {
                return false;
            }
        }

        // 3. Vérification de la permission requise
        if ($transition->getRequiredPermission() !== null) {
            if (!$this->accessScope->hasPermission($user, $transition->getRequiredPermission())) {
                return false;
            }
        }

        // 4. Vérification de la portée organisationnelle (l'utilisateur doit appartenir à la structure détentrice ou avoir autorité hiérarchique)
        $currentStructure = $case->getCurrentStructure();
        if ($currentStructure !== null) {
            $userUnit = $user->getOrganizationUnit();
            if ($userUnit === null) {
                return false;
            }

            // L'utilisateur est autorisé s'il est dans la structure détentrice ou dans une structure parente/englobante
            if ($userUnit->getId() !== $currentStructure->getId() && !$currentStructure->isWithin($userUnit)) {
                return false;
            }
        }

        // 5. Si une tâche est attribuée nominativement à un agent précis
        $activeTask = $case->getWorkflowInstance()?->getActiveTask();
        if ($activeTask !== null && $activeTask->getTargetUser() !== null) {
            if ($activeTask->getTargetUser()->getId() !== $user->getId()) {
                // Seul l'assignataire nominatif ou un supérieur direct peut agir
                if (!$this->accessScope->canAccessStructure($user, $activeTask->getTargetStructure() ?? $currentStructure)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Application stricte de la règle RB-005 (Le Ministre ne peut pas contourner le Secrétaire Général)
     */
    private function applyRuleRb005Check(WorkflowStep $sourceStep, WorkflowStep $targetStep, ?OrganizationUnit $targetStructure): void
    {
        // Si l'étape source est la revue ministérielle (MINISTRE_REVIEW)
        if ($sourceStep->getCode() === 'MINISTRE_REVIEW') {
            // Dans le circuit standard, la destination autorisée doit obligatoirement être SG_REVIEW (ou retour vers CABINET_RECEPTION, ou maintien sur place MINISTRE_REVIEW pour annotation)
            if ($targetStep->getCode() !== 'SG_REVIEW' && $targetStep->getCode() !== 'CABINET_RECEPTION' && $targetStep->getCode() !== 'MINISTRE_REVIEW') {
                throw new InvalidWorkflowTransitionException(
                    'RÈGLE ABSOLUE RB-005 : Le Ministre ne peut pas contourner le Secrétaire Général dans le circuit administratif standard.'
                );
            }

            // Si une structure personnalisée est passée, elle ne doit pas être une direction technique
            if ($targetStructure !== null) {
                $type = $targetStructure->getType();
                if ($type->value === 'DIRECTION' || $type->value === 'SERVICE') {
                    throw new InvalidWorkflowTransitionException(
                        'RÈGLE ABSOLUE RB-005 : Transmission directe du Ministre à une Direction technique refusée. Le dossier doit transiter par le Secrétaire Général.'
                    );
                }
            }
        }
    }

    /**
     * Met à jour le code de statut du dossier (DossierCase) en fonction de l'action de workflow
     */
    private function updateCaseStatusForAction(DossierCase $case, WorkflowAction $action, WorkflowStep $targetStep, User $user): void
    {
        $status = match ($action) {
            WorkflowAction::Submit => 'SUBMITTED',
            WorkflowAction::Annotate => 'ANNOTATED',
            WorkflowAction::Orient, WorkflowAction::Forward => 'FORWARDED',
            WorkflowAction::Assign => 'ASSIGNED',
            WorkflowAction::TakeInCharge => 'IN_PROGRESS',
            WorkflowAction::Process => 'IN_ANALYSIS',
            WorkflowAction::Return => 'RETURNED',
            WorkflowAction::Validate => 'VALIDATED',
            WorkflowAction::Reject => 'REJECTED',
            WorkflowAction::Close => 'COMPLETED',
            WorkflowAction::Release => 'RELEASED',
            WorkflowAction::Escalate => 'IN_PROGRESS',
        };

        if ($targetStep->isFinal()) {
            if ($action === WorkflowAction::Close) {
                $case->close($user);
            } elseif ($action === WorkflowAction::Release) {
                $case->setStatusCode('RELEASED');
            }
        } else {
            $case->setStatusCode($status);
        }
    }

    private function mapActionToCaseEventType(WorkflowAction $action): CaseEventType
    {
        return match ($action) {
            WorkflowAction::Submit       => CaseEventType::CaseUpdated,
            WorkflowAction::Annotate     => CaseEventType::NoteAdded,
            WorkflowAction::Orient,
            WorkflowAction::Forward      => CaseEventType::CaseForwarded,
            WorkflowAction::Assign       => CaseEventType::DossierAffecte,
            WorkflowAction::TakeInCharge => CaseEventType::TakenInCharge,
            WorkflowAction::Process      => CaseEventType::Processed,
            WorkflowAction::Return       => CaseEventType::Returned,
            WorkflowAction::Validate     => CaseEventType::CaseValidated,
            WorkflowAction::Reject       => CaseEventType::Rejected,
            WorkflowAction::Close        => CaseEventType::Completed,
            WorkflowAction::Release      => CaseEventType::Released,
            WorkflowAction::Escalate     => CaseEventType::WorkflowEscalated,
        };
    }
}
