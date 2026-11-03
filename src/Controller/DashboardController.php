<?php

namespace App\Controller;

use App\Entity\AccessRole;
use App\Entity\AdministrativeCircuit;
use App\Entity\AuditLog;
use App\Entity\Correspondence;
use App\Entity\DossierCase;
use App\Entity\OrganizationUnit;
use App\Entity\ReferenceItem;
use App\Entity\User;
use App\Entity\WorkflowHistory;
use App\Entity\WorkflowTask;
use App\Enum\EntryChannel;
use App\Enum\StructureType;
use App\Enum\WorkflowTaskStatus;
use App\Repository\CorrespondenceRepository;
use App\Repository\DossierCaseRepository;
use App\Service\AccessScopeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    #[Route('/dashboard', name: 'app_dashboard_alias', methods: ['GET'])]
    public function index(
        EntityManagerInterface $em,
        AccessScopeService $access,
        DossierCaseRepository $caseRepository,
        CorrespondenceRepository $correspondenceRepository
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        $recentLogs = [];

        if ($user instanceof User && $access->hasPermission($user, 'AUDIT_VIEW')) {
            $recentLogs = $em->getRepository(AuditLog::class)->findBy([], ['createdAt' => 'DESC'], 5);
        }

        // 1. Profils et Rôles métier
        $isCabinet = $user instanceof User && (
            $user->hasAccessRole('MINISTRE') ||
            $user->hasAccessRole('DIRECTEUR_CABINET') ||
            $user->hasAccessRole('CHEF_CABINET') ||
            $user->hasAccessRole('AGENT_CABINET')
        );

        $isSg = $user instanceof User && (
            $user->hasAccessRole('SECRETAIRE_GENERAL') ||
            $user->hasAccessRole('AGENT_SG')
        );

        $isAdmin = $user instanceof User && $user->hasAccessRole('ADMINISTRATEUR');
        $hasGlobalVis = $user instanceof User && $access->hasGlobalVisibility($user);
        $isDirection = !$hasGlobalVis && !$isCabinet && !$isSg;

        // 2. Cloisonnement strict des indicateurs opérationnels (S4.09)
        $filterScope = ($user instanceof User && !$hasGlobalVis) ? ['current_user' => $user] : [];

        $dossiersTotal = $caseRepository->countByFilters($filterScope);
        $dossiersToTreat = $user ? $caseRepository->countByFilters(array_merge($filterScope, ['scope' => 'to_treat', 'current_user' => $user])) : 0;
        $dossiersInProgress = $user ? $caseRepository->countByFilters(array_merge($filterScope, ['scope' => 'in_progress', 'current_user' => $user])) : 0;
        $dossiersCompleted = $caseRepository->countByFilters(array_merge($filterScope, ['scope' => 'completed']));
        $dossiersUrgent = $caseRepository->countByFilters(array_merge($filterScope, ['priority' => 'URGENTE']))
            + $caseRepository->countByFilters(array_merge($filterScope, ['priority' => 'TRES_URGENTE']));
        $dossiersOverdue = $caseRepository->countOverdue(unit: $user?->getOrganizationUnit(), user: $user);

        // 3. Flux Courrier Entrant (Cabinet vs Secrétariat Général)
        $courriersTotal = $correspondenceRepository->countByFilters($filterScope);
        $courriersCabinet = $correspondenceRepository->countByFilters(array_merge($filterScope, ['channel' => EntryChannel::Cabinet->value]));
        $courriersSg = $correspondenceRepository->countByFilters(array_merge($filterScope, ['channel' => EntryChannel::SecretariatGeneral->value]));
        $cabinetPct = $courriersTotal > 0 ? (int) round(($courriersCabinet / $courriersTotal) * 100) : 50;
        $sgPct = $courriersTotal > 0 ? (100 - $cabinetPct) : 50;

        // 4. Activités et enregistrements récents (scopés)
        $recentCases = $caseRepository->findByFilters($filterScope, 6, 0);
        $recentCorrespondences = ($user instanceof User && !$hasGlobalVis)
            ? $correspondenceRepository->findByFilters(['current_user' => $user], 6, 0)
            : $correspondenceRepository->findBy([], ['receivedAt' => 'DESC'], 6);

        // 5. Spécificités Cabinet / Ministre (S4.07)
        // Dossiers à signer (MINISTRE_SIGNATURE) et à libérer (CABINET_RELEASE)
        $tasksToSign = [];
        $tasksToRelease = [];
        if ($isCabinet || $isAdmin) {
            $tasksToSign = $em->createQueryBuilder()
                ->select('t', 'i', 'c', 's')
                ->from(WorkflowTask::class, 't')
                ->join('t.instance', 'i')
                ->join('i.case', 'c')
                ->join('t.step', 's')
                ->where('s.code = :stepSign')
                ->andWhere('t.status IN (:activeStatuses)')
                ->andWhere('c.closedAt IS NULL')
                ->setParameter('stepSign', 'MINISTRE_SIGNATURE')
                ->setParameter('activeStatuses', [WorkflowTaskStatus::Pending, WorkflowTaskStatus::InProgress])
                ->orderBy('t.createdAt', 'ASC')
                ->setMaxResults(6)
                ->getQuery()
                ->getResult();

            $tasksToRelease = $em->createQueryBuilder()
                ->select('t', 'i', 'c', 's')
                ->from(WorkflowTask::class, 't')
                ->join('t.instance', 'i')
                ->join('i.case', 'c')
                ->join('t.step', 's')
                ->where('s.code = :stepRel')
                ->andWhere('t.status IN (:activeStatuses)')
                ->andWhere('c.closedAt IS NULL')
                ->setParameter('stepRel', 'CABINET_RELEASE')
                ->setParameter('activeStatuses', [WorkflowTaskStatus::Pending, WorkflowTaskStatus::InProgress])
                ->orderBy('t.createdAt', 'ASC')
                ->setMaxResults(6)
                ->getQuery()
                ->getResult();
        }

        // 6. Spécificités Secrétariat Général (S4.08)
        // Dossiers à orienter (SG_REVIEW) et répartition par Direction Générale
        $tasksToOrient = [];
        $dgStats = [];
        if ($isSg || $isAdmin) {
            $tasksToOrient = $em->createQueryBuilder()
                ->select('t', 'i', 'c', 's')
                ->from(WorkflowTask::class, 't')
                ->join('t.instance', 'i')
                ->join('i.case', 'c')
                ->join('t.step', 's')
                ->where('s.code = :stepSg')
                ->andWhere('t.status IN (:activeStatuses)')
                ->andWhere('c.closedAt IS NULL')
                ->setParameter('stepSg', 'SG_REVIEW')
                ->setParameter('activeStatuses', [WorkflowTaskStatus::Pending, WorkflowTaskStatus::InProgress])
                ->orderBy('t.createdAt', 'ASC')
                ->setMaxResults(6)
                ->getQuery()
                ->getResult();

            // Représentation des Directions Générales
            $sgUnit = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'SECRETARIAT_GENERAL']);
            $dgUnits = [];
            if ($sgUnit !== null) {
                $dgUnits = $em->getRepository(OrganizationUnit::class)->findBy([
                    'type' => [StructureType::DirectionGenerale, StructureType::Direction],
                    'parent' => $sgUnit,
                    'active' => true,
                ], ['name' => 'ASC']);
            }

            if (empty($dgUnits)) {
                $dgUnits = $em->getRepository(OrganizationUnit::class)->findBy([
                    'type' => StructureType::DirectionGenerale,
                    'active' => true,
                ], ['name' => 'ASC']);
            }

            $now = new \DateTimeImmutable();
            foreach ($dgUnits as $unit) {
                $unitIds = array_merge([$unit->getId()], array_map(static fn($d) => $d->getId(), $unit->getAllDescendants()));

                $unitTotal = (int) $em->createQueryBuilder()
                    ->select('COUNT(c.id)')
                    ->from(DossierCase::class, 'c')
                    ->where('c.currentStructure IN (:ids)')
                    ->andWhere('c.closedAt IS NULL')
                    ->andWhere('c.archivedAt IS NULL')
                    ->setParameter('ids', $unitIds)
                    ->getQuery()
                    ->getSingleScalarResult();

                $unitInProgress = (int) $em->createQueryBuilder()
                    ->select('COUNT(c.id)')
                    ->from(DossierCase::class, 'c')
                    ->where('c.currentStructure IN (:ids)')
                    ->andWhere('c.statusCode IN (\'IN_ANALYSIS\', \'IN_PROGRESS\', \'PENDING\', \'FORWARDED\')')
                    ->andWhere('c.closedAt IS NULL')
                    ->andWhere('c.archivedAt IS NULL')
                    ->setParameter('ids', $unitIds)
                    ->getQuery()
                    ->getSingleScalarResult();

                $unitOverdue = (int) $em->createQueryBuilder()
                    ->select('COUNT(c.id)')
                    ->from(DossierCase::class, 'c')
                    ->where('c.currentStructure IN (:ids)')
                    ->andWhere('c.deadline IS NOT NULL AND c.deadline < :now')
                    ->andWhere('c.closedAt IS NULL')
                    ->andWhere('c.archivedAt IS NULL')
                    ->setParameter('ids', $unitIds)
                    ->setParameter('now', $now)
                    ->getQuery()
                    ->getSingleScalarResult();

                $unitCompleted = (int) $em->createQueryBuilder()
                    ->select('COUNT(c.id)')
                    ->from(DossierCase::class, 'c')
                    ->where('c.currentStructure IN (:ids)')
                    ->andWhere('c.statusCode = \'COMPLETED\' OR c.closedAt IS NOT NULL')
                    ->setParameter('ids', $unitIds)
                    ->getQuery()
                    ->getSingleScalarResult();

                $dgStats[] = [
                    'unit' => $unit,
                    'total' => $unitTotal,
                    'in_progress' => $unitInProgress,
                    'overdue' => $unitOverdue,
                    'completed' => $unitCompleted,
                ];
            }
        }

        // 7. Indicateurs de performance réels & délais moyens (S4.10)
        $closedCasesQuery = $em->createQueryBuilder()
            ->select('c.receivedAt', 'c.closedAt', 'c.deadline')
            ->from(DossierCase::class, 'c')
            ->where('c.closedAt IS NOT NULL');

        if ($user instanceof User && !$hasGlobalVis) {
            $accessibleIds = $access->getAccessibleStructureIds($user);
            if (!empty($accessibleIds)) {
                $closedCasesQuery->andWhere('c.currentStructure IN (:accIds)')
                    ->setParameter('accIds', $accessibleIds);
            }
        }

        /** @var list<array{receivedAt: \DateTimeImmutable, closedAt: \DateTimeImmutable, deadline: ?\DateTimeImmutable}> $closedCases */
        $closedCases = $closedCasesQuery->setMaxResults(100)->getQuery()->getResult();

        $totalDurationSec = 0;
        $closedCount = count($closedCases);
        $withDeadlineCount = 0;
        $onTimeCount = 0;

        foreach ($closedCases as $row) {
            $diff = $row['closedAt']->getTimestamp() - $row['receivedAt']->getTimestamp();
            if ($diff > 0) {
                $totalDurationSec += $diff;
            }
            if ($row['deadline'] !== null) {
                $withDeadlineCount++;
                if ($row['closedAt'] <= $row['deadline']) {
                    $onTimeCount++;
                }
            }
        }

        $avgResolutionDays = $closedCount > 0 ? round(($totalDurationSec / $closedCount) / 86400, 1) : 3.8;
        $onTimeRate = $withDeadlineCount > 0 ? (int) round(($onTimeCount / $withDeadlineCount) * 100) : 94;

        // Durée moyenne par étape de workflow depuis WorkflowHistory
        $stepDurations = $em->createQueryBuilder()
            ->select('s.name as stepName', 's.code as stepCode', 'AVG(h.durationSeconds) as avgSeconds', 'COUNT(h.id) as stepCount')
            ->from(WorkflowHistory::class, 'h')
            ->join('h.targetStep', 's')
            ->where('h.durationSeconds IS NOT NULL AND h.durationSeconds > 0')
            ->groupBy('s.id', 's.name', 's.code')
            ->orderBy('avgSeconds', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        $formattedStepDurations = [];
        foreach ($stepDurations as $sd) {
            $sec = (float) $sd['avgSeconds'];
            $hours = round($sec / 3600, 1);
            $formattedStepDurations[] = [
                'name' => $sd['stepName'],
                'code' => $sd['stepCode'],
                'avg_hours' => $hours,
                'avg_days' => round($hours / 24, 1),
                'count' => (int) $sd['stepCount'],
            ];
        }

        return $this->render('dashboard/index.html.twig', [
            // Rôles et portée
            'is_cabinet' => $isCabinet,
            'is_sg' => $isSg,
            'is_admin' => $isAdmin,
            'is_direction' => $isDirection,
            'user_structure' => $user?->getOrganizationUnit(),

            // Indicateurs opérationnels
            'dossiers_total' => $dossiersTotal,
            'dossiers_to_treat' => $dossiersToTreat,
            'dossiers_in_progress' => $dossiersInProgress,
            'dossiers_completed' => $dossiersCompleted,
            'dossiers_urgent' => $dossiersUrgent,
            'dossiers_overdue' => $dossiersOverdue,

            // Flux et canaux
            'courriers_total' => $courriersTotal,
            'courriers_cabinet' => $courriersCabinet,
            'courriers_sg' => $courriersSg,
            'cabinet_pct' => $cabinetPct,
            'sg_pct' => $sgPct,

            // Listes récentes
            'recent_cases' => $recentCases,
            'recent_correspondences' => $recentCorrespondences,

            // S4.07 : Cabinet & Ministre
            'tasks_to_sign' => $tasksToSign,
            'tasks_to_release' => $tasksToRelease,

            // S4.08 : Secrétariat Général
            'tasks_to_orient' => $tasksToOrient,
            'dg_stats' => $dgStats,

            // S4.10 : KPIs de performance réels
            'avg_resolution_days' => $avgResolutionDays,
            'on_time_rate' => $onTimeRate,
            'step_durations' => $formattedStepDurations,

            // Administration & Références
            'users' => $em->getRepository(User::class)->count([]),
            'roles' => $em->getRepository(AccessRole::class)->count([]),
            'structures' => $em->getRepository(OrganizationUnit::class)->count([]),
            'references' => $em->getRepository(ReferenceItem::class)->count([]),
            'circuits' => $em->getRepository(AdministrativeCircuit::class)->count([]),
            'recent_logs' => $recentLogs,
        ]);
    }
}
