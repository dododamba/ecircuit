<?php

namespace App\Command;

use App\Entity\CaseEvent;
use App\Entity\DossierCase;
use App\Entity\Notification;
use App\Entity\OrganizationUnit;
use App\Entity\User;
use App\Entity\WorkflowHistory;
use App\Entity\WorkflowTask;
use App\Enum\AuditAction;
use App\Enum\AuditResult;
use App\Enum\CaseEventType;
use App\Enum\WorkflowAction;
use App\Enum\WorkflowTaskStatus;
use App\Service\AuditLogger;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:workflow:check-deadlines',
    description: 'Vérifie les échéances réglementaires des dossiers et tâches de workflow, déclenche les escalades et envoie les alertes de retard (S3.10 / S4.06).'
)]
final class CheckWorkflowDeadlinesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationService $notificationService,
        private readonly AuditLogger $auditLogger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule la détection des retards sans créer d\'escalades ni de notifications.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force la ré-escalade même si la tâche a déjà été marquée comme escaladée.')
            ->addOption('case-ref', null, InputOption::VALUE_OPTIONAL, 'Filtrer sur une référence spécifique de dossier.')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Nombre maximum de tâches à traiter.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        $now = new \DateTimeImmutable();
        $dateKey = $now->format('Y-m-d');

        $io->title('Vérification des Échéances & Alertes de Retard (S3.10 / S4.06)');
        $io->info(sprintf('Horodatage de référence : %s', $now->format('d/m/Y H:i:s')));

        // 1. Recherche des tâches actives dont l'échéance est dépassée
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('t', 'i', 'c', 's')
            ->from(WorkflowTask::class, 't')
            ->join('t.instance', 'i')
            ->join('i.case', 'c')
            ->join('t.step', 's')
            ->where('t.status IN (:activeStatuses)')
            ->andWhere('c.closedAt IS NULL')
            ->andWhere('c.archivedAt IS NULL')
            ->setParameter('activeStatuses', [WorkflowTaskStatus::Pending, WorkflowTaskStatus::InProgress]);

        if (!$force) {
            $qb->andWhere('t.escalatedAt IS NULL');
        }

        $caseRef = $input->getOption('case-ref');
        if ($caseRef !== null && trim((string) $caseRef) !== '') {
            $qb->andWhere('c.reference = :caseRef')
                ->setParameter('caseRef', trim((string) $caseRef));
        }

        $limit = $input->getOption('limit');
        if ($limit !== null && is_numeric($limit)) {
            $qb->setMaxResults((int) $limit);
        }

        /** @var WorkflowTask[] $tasks */
        $tasks = $qb->getQuery()->getResult();

        $overdueCount = 0;
        $escalatedCount = 0;
        $notificationsCount = 0;

        foreach ($tasks as $task) {
            $instance = $task->getInstance();
            $case = $instance->getCase();
            $step = $task->getStep();

            // Détermination de l'échéance effective (celle de la tâche ou celle du dossier global)
            $deadline = $task->getDeadline() ?? $case->getDeadline() ?? $instance->getStepDeadline();
            if ($deadline === null || $deadline >= $now) {
                continue;
            }

            $overdueCount++;
            $diff = $now->diff($deadline);
            $overdueHours = ($diff->days * 24) + $diff->h;
            $overdueFormatted = sprintf('%dj %dh', $diff->days, $diff->h);

            $currentStructure = $task->getTargetStructure() ?? $case->getCurrentStructure();
            $currentAssignee = $task->getTargetUser() ?? $case->getCurrentAssignee();
            $link = '/dossiers/' . $case->getId();

            $io->text(sprintf(
                '⚠️  Dossier <info>%s</info> | Étape: %s | Retard: %s | Structure: %s',
                $case->getReference(),
                $step->getName(),
                $overdueFormatted,
                $currentStructure ? $currentStructure->getName() : 'Non assignée'
            ));

            if ($isDryRun) {
                continue;
            }

            // A. Notification à l'agent assignataire ou aux membres de la structure
            $agentIdempotencyKey = sprintf('delay_alert_task_%d_agent_%s', $task->getId(), $dateKey);
            $agentTitle = sprintf('⚠️ Alerte de retard : %s', $case->getReference());
            $agentMsg = sprintf(
                'Le dossier %s a dépassé son délai réglementaire à l\'étape "%s" (retard estimé : %s). Un traitement prioritaire est requis.',
                $case->getReference(),
                $step->getName(),
                $overdueFormatted
            );

            if ($currentAssignee !== null) {
                $this->notificationService->notifyUser(
                    $currentAssignee,
                    $agentTitle,
                    $agentMsg,
                    'delay_alert',
                    $link,
                    'ki-information-2',
                    $agentIdempotencyKey
                );
                $notificationsCount++;
            } elseif ($currentStructure !== null) {
                $structureUsers = $this->entityManager->getRepository(User::class)->findBy(['organizationUnit' => $currentStructure]);
                foreach ($structureUsers as $sUser) {
                    $uKey = sprintf('delay_alert_task_%d_u%d_%s', $task->getId(), $sUser->getId(), $dateKey);
                    $this->notificationService->notifyUser(
                        $sUser,
                        $agentTitle,
                        $agentMsg,
                        'delay_alert',
                        $link,
                        'ki-information-2',
                        $uKey
                    );
                    $notificationsCount++;
                }
            }

            // B. Notification d'escalade aux supérieurs hiérarchiques (structure parente ou SG)
            $parentStructure = $currentStructure?->getParent();
            $superiors = [];
            if ($parentStructure !== null) {
                $superiors = $this->entityManager->getRepository(User::class)->findBy(['organizationUnit' => $parentStructure]);
            }
            if (empty($superiors)) {
                $sgUnit = $this->entityManager->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'SECRETARIAT_GENERAL']);
                if ($sgUnit !== null) {
                    $superiors = $this->entityManager->getRepository(User::class)->findBy(['organizationUnit' => $sgUnit]);
                }
            }

            $supTitle = sprintf('⚠️ Escalade hiérarchique : %s en retard', $case->getReference());
            $supMsg = sprintf(
                'Dépassement de délai sur le dossier %s à l\'étape "%s" (détenu par %s, retard : %s). Intervention ou réorientation requise.',
                $case->getReference(),
                $step->getName(),
                $currentStructure ? $currentStructure->getName() : 'Structure inconnue',
                $overdueFormatted
            );

            foreach ($superiors as $superior) {
                if ($currentAssignee !== null && $superior->getId() === $currentAssignee->getId()) {
                    continue;
                }
                $supKey = sprintf('delay_escalation_task_%d_sup%d_%s', $task->getId(), $superior->getId(), $dateKey);
                $this->notificationService->notifyUser(
                    $superior,
                    $supTitle,
                    $supMsg,
                    'delay_alert',
                    $link,
                    'ki-arrow-up',
                    $supKey
                );
                $notificationsCount++;
            }

            // C. Traçabilité dans l'historique de Workflow (WorkflowHistory)
            $durationSeconds = $task->getStartedAt()
                ? $now->getTimestamp() - $task->getStartedAt()->getTimestamp()
                : $now->getTimestamp() - $task->getCreatedAt()->getTimestamp();

            $history = new WorkflowHistory(
                $instance,
                $step,
                $step,
                WorkflowAction::Escalate,
                null,
                $currentStructure,
                sprintf('Escalade automatique : délai réglementaire dépassé de %s.', $overdueFormatted),
                [
                    'task_id' => $task->getId(),
                    'deadline' => $deadline->format(\DateTimeInterface::ATOM),
                    'overdue_hours' => $overdueHours,
                ],
                $durationSeconds
            );
            $this->entityManager->persist($history);

            // D. Traçabilité dans la Timeline métier du Dossier (CaseEvent - Module 9)
            $event = new CaseEvent(
                $case,
                CaseEventType::WorkflowEscalated,
                sprintf('Escalade pour dépassement de délai (%s)', $step->getName()),
                null,
                sprintf('Délai dépassé de %s à l\'étape "%s". Notification d\'alerte transmise à l\'agent et à la hiérarchie.', $overdueFormatted, $step->getName()),
                action: 'WORKFLOW_ESCALATED',
                oldValue: null,
                newValue: 'ESCALATED',
                organizationUnit: $currentStructure,
                metadata: [
                    'task_id' => $task->getId(),
                    'step_code' => $step->getCode(),
                    'overdue_hours' => $overdueHours,
                ]
            );
            $case->addEvent($event);
            $this->entityManager->persist($event);

            // E. Journal d'Audit technique de sécurité (Module 10)
            $this->auditLogger->log(
                event: AuditAction::WorkflowEscalated,
                success: true,
                user: null,
                request: null,
                context: [
                    'case_id' => $case->getId(),
                    'case_reference' => $case->getReference(),
                    'task_id' => $task->getId(),
                    'step_code' => $step->getCode(),
                    'structure_code' => $currentStructure?->getCode(),
                    'assignee_id' => $currentAssignee?->getId(),
                    'overdue_hours' => $overdueHours,
                    'notifications_sent' => count($superiors) + ($currentAssignee ? 1 : 0),
                ],
                entityType: 'DossierCase',
                entityId: (string) $case->getId(),
                result: AuditResult::Success,
                flush: false
            );

            // F. Marquer la tâche comme escaladée
            $task->markEscalated($now);
            $escalatedCount++;
        }

        if (!$isDryRun) {
            $this->entityManager->flush();
        }

        $io->newLine();
        $io->success(sprintf(
            '%d tâche(s) en retard identifiée(s), %d escaladée(s), %d notification(s) d\'alerte émise(s)%s.',
            $overdueCount,
            $escalatedCount,
            $notificationsCount,
            $isDryRun ? ' [MODE SIMULATION / DRY-RUN]' : ''
        ));

        return Command::SUCCESS;
    }
}
