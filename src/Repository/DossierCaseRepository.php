<?php

namespace App\Repository;

use App\Entity\DossierCase;
use App\Entity\OrganizationUnit;
use App\Entity\User;
use App\Enum\EntryChannel;
use App\Service\AccessScopeService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DossierCase>
 */
class DossierCaseRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly AccessScopeService $accessScopeService
    ) {
        parent::__construct($registry, DossierCase::class);
    }

    /**
     * Recherche avancée des dossiers avec prise en compte des scopes fonctionnels et du cloisonnement structurel
     *
     * @param array<string, mixed> $filters
     * @return list<DossierCase>
     */
    public function findByFilters(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('dc')
            ->leftJoin('dc.sender', 's')->addSelect('s')
            ->leftJoin('dc.currentStructure', 'st')->addSelect('st')
            ->leftJoin('dc.currentAssignee', 'u')->addSelect('u')
            ->leftJoin('dc.caseType', 'ct')->addSelect('ct')
            ->orderBy('dc.receivedAt', 'DESC')
            ->addOrderBy('dc.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $this->applyCriteria($qb, $filters);

        /** @var list<DossierCase> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Compte total des dossiers filtrés
     *
     * @param array<string, mixed> $filters
     */
    public function countByFilters(array $filters = []): int
    {
        $qb = $this->createQueryBuilder('dc')
            ->select('COUNT(DISTINCT dc.id)')
            ->leftJoin('dc.sender', 's')
            ->leftJoin('dc.currentStructure', 'st')
            ->leftJoin('dc.currentAssignee', 'u')
            ->leftJoin('dc.caseType', 'ct');

        $this->applyCriteria($qb, $filters);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Calcule les effectifs de dossiers pour chacun des 6 onglets fonctionnels du Module 8
     *
     * @param array<string, mixed> $baseFilters
     * @return array<string, int>
     */
    public function getScopeCounts(User $user, array $baseFilters = []): array
    {
        $scopes = ['mine', 'received', 'to_treat', 'in_progress', 'returned', 'completed'];
        $counts = [];

        foreach ($scopes as $sc) {
            $f = $baseFilters;
            $f['scope'] = $sc;
            $f['current_user'] = $user;
            $counts[$sc] = $this->countByFilters($f);
        }

        $fAll = $baseFilters;
        unset($fAll['scope']);
        $fAll['current_user'] = $user;
        $counts['all'] = $this->countByFilters($fAll);

        return $counts;
    }

    /**
     * Applique l'ensemble des prédicats, filtres métier et restrictions de cloisonnement sur le QueryBuilder
     *
     * @param array<string, mixed> $filters
     */
    private function applyCriteria(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['search'])) {
            $search = '%' . trim((string) $filters['search']) . '%';
            $qb->andWhere('dc.reference LIKE :search OR dc.subject LIKE :search OR s.name LIKE :search OR s.organisation LIKE :search')
               ->setParameter('search', $search);
        }

        if (!empty($filters['status'])) {
            $qb->andWhere('dc.statusCode = :status')
               ->setParameter('status', strtoupper(trim((string) $filters['status'])));
        }

        if (!empty($filters['priority'])) {
            $qb->andWhere('dc.priorityCode = :priority')
               ->setParameter('priority', strtoupper(trim((string) $filters['priority'])));
        }

        if (!empty($filters['channel'])) {
            $channelEnum = EntryChannel::tryFrom((string) $filters['channel']);
            if ($channelEnum) {
                $qb->andWhere('dc.entryChannel = :channel')
                   ->setParameter('channel', $channelEnum);
            }
        }

        if (!empty($filters['structure_id'])) {
            $qb->andWhere('st.id = :structureId')
               ->setParameter('structureId', (int) $filters['structure_id']);
        }

        if (!empty($filters['assignee_id'])) {
            $qb->andWhere('u.id = :assigneeId')
               ->setParameter('assigneeId', (int) $filters['assignee_id']);
        }

        if (!empty($filters['case_type_id'])) {
            $qb->andWhere('ct.id = :caseTypeId')
               ->setParameter('caseTypeId', (int) $filters['case_type_id']);
        }

        if (!empty($filters['document_type'])) {
            $docTypeVal = trim((string) $filters['document_type']);
            $qb->andWhere(
                'EXISTS (SELECT 1 FROM App\Entity\Document d_flt WHERE d_flt.case = dc AND UPPER(d_flt.type) = :docTypeCode) ' .
                'OR EXISTS (SELECT 1 FROM App\Entity\Correspondence c_flt LEFT JOIN c_flt.documentType c_dt WHERE c_flt.case = dc AND (UPPER(c_dt.code) = :docTypeCode OR c_dt.id = :docTypeId))'
            )
               ->setParameter('docTypeCode', strtoupper($docTypeVal))
               ->setParameter('docTypeId', is_numeric($docTypeVal) ? (int) $docTypeVal : -1);
        }

        if (!empty($filters['date_from'])) {
            try {
                $from = new \DateTimeImmutable((string) $filters['date_from'] . ' 00:00:00');
                $qb->andWhere('dc.receivedAt >= :dateFrom')
                   ->setParameter('dateFrom', $from);
            } catch (\Throwable) {}
        }

        if (!empty($filters['date_to'])) {
            try {
                $to = new \DateTimeImmutable((string) $filters['date_to'] . ' 23:59:59');
                $qb->andWhere('dc.receivedAt <= :dateTo')
                   ->setParameter('dateTo', $to);
            } catch (\Throwable) {}
        }

        if (!empty($filters['created_from'])) {
            try {
                $cFrom = new \DateTimeImmutable((string) $filters['created_from'] . ' 00:00:00');
                $qb->andWhere('dc.createdAt >= :createdFrom')
                   ->setParameter('createdFrom', $cFrom);
            } catch (\Throwable) {}
        }

        if (!empty($filters['created_to'])) {
            try {
                $cTo = new \DateTimeImmutable((string) $filters['created_to'] . ' 23:59:59');
                $qb->andWhere('dc.createdAt <= :createdTo')
                   ->setParameter('createdTo', $cTo);
            } catch (\Throwable) {}
        }

        if (!empty($filters['overdue_only'])) {
            $qb->andWhere('dc.deadline IS NOT NULL AND dc.deadline < :overdueNow AND dc.closedAt IS NULL AND dc.archivedAt IS NULL')
               ->setParameter('overdueNow', new \DateTimeImmutable());
        }

        /** @var User|null $user */
        $user = $filters['current_user'] ?? null;

        // Cloisonnement inter-structures strict (S1.08 / S5.04)
        if ($user instanceof User && !$this->accessScopeService->hasGlobalVisibility($user)) {
            $accessibleIds = $this->accessScopeService->getAccessibleStructureIds($user);
            if (!empty($accessibleIds)) {
                $qb->andWhere(
                    '(dc.currentAssignee = :accessScopeUser ' .
                    'OR dc.currentStructure IN (:accessibleStructureIds) ' .
                    'OR EXISTS (SELECT 1 FROM App\Entity\CaseTransmission ct_acc WHERE ct_acc.case = dc AND (ct_acc.transmittedBy = :accessScopeUser OR ct_acc.sourceUnit IN (:accessibleStructureIds) OR ct_acc.targetUnit IN (:accessibleStructureIds))) ' .
                    'OR EXISTS (SELECT 1 FROM App\Entity\CaseEvent ce_acc WHERE ce_acc.case = dc AND (ce_acc.actor = :accessScopeUser OR ce_acc.organizationUnit IN (:accessibleStructureIds))))'
                )
                   ->setParameter('accessScopeUser', $user)
                   ->setParameter('accessibleStructureIds', $accessibleIds);
            } else {
                $qb->andWhere(
                    '(dc.currentAssignee = :accessScopeUser ' .
                    'OR dc.currentStructure IS NULL ' .
                    'OR EXISTS (SELECT 1 FROM App\Entity\CaseTransmission ct_acc WHERE ct_acc.case = dc AND ct_acc.transmittedBy = :accessScopeUser) ' .
                    'OR EXISTS (SELECT 1 FROM App\Entity\CaseEvent ce_acc WHERE ce_acc.case = dc AND ce_acc.actor = :accessScopeUser))'
                )
                   ->setParameter('accessScopeUser', $user);
            }
        }

        // Application des scopes fonctionnels Module 8
        $scope = !empty($filters['scope']) ? strtolower(trim((string) $filters['scope'])) : null;

        if ($scope && $user instanceof User) {
            $userUnit = $user->getOrganizationUnit();

            switch ($scope) {
                case 'mine':
                    $qb->andWhere('dc.currentAssignee = :scopeUser')
                       ->setParameter('scopeUser', $user);
                    break;

                case 'received':
                    if ($userUnit !== null) {
                        $qb->andWhere('dc.currentStructure = :userUnit AND (dc.currentAssignee IS NULL OR dc.statusCode IN (\'RECEIVED\', \'REGISTERED\', \'SUBMITTED\', \'FORWARDED\'))')
                           ->setParameter('userUnit', $userUnit);
                    } else {
                        $qb->andWhere('dc.statusCode IN (\'RECEIVED\', \'REGISTERED\', \'SUBMITTED\', \'FORWARDED\')');
                    }
                    $qb->andWhere('dc.statusCode NOT IN (\'COMPLETED\', \'CLOSED\', \'ARCHIVED\')');
                    break;

                case 'to_treat':
                    if ($userUnit !== null) {
                        $qb->andWhere('(dc.currentAssignee = :scopeUser OR (dc.currentStructure = :userUnit AND dc.currentAssignee IS NULL))')
                           ->setParameter('scopeUser', $user)
                           ->setParameter('userUnit', $userUnit);
                    } else {
                        $qb->andWhere('dc.currentAssignee = :scopeUser')
                           ->setParameter('scopeUser', $user);
                    }
                    $qb->andWhere('dc.statusCode IN (\'ASSIGNED\', \'RETURNED\', \'TO_TREAT\', \'RECEIVED\', \'SUBMITTED\')')
                       ->andWhere('dc.closedAt IS NULL AND dc.archivedAt IS NULL');
                    break;

                case 'in_progress':
                    $qb->andWhere('dc.statusCode IN (\'IN_PROGRESS\', \'IN_ANALYSIS\')')
                       ->andWhere('dc.closedAt IS NULL AND dc.archivedAt IS NULL');
                    if (!$this->accessScopeService->hasGlobalVisibility($user) && $userUnit !== null) {
                        $qb->andWhere('(dc.currentAssignee = :scopeUser OR dc.currentStructure = :userUnit)')
                           ->setParameter('scopeUser', $user)
                           ->setParameter('userUnit', $userUnit);
                    }
                    break;

                case 'returned':
                    $qb->andWhere('dc.statusCode IN (\'RETURNED\', \'REJECTED\')')
                       ->andWhere('dc.archivedAt IS NULL');
                    if (!$this->accessScopeService->hasGlobalVisibility($user) && $userUnit !== null) {
                        $qb->andWhere('(dc.currentAssignee = :scopeUser OR dc.currentStructure = :userUnit)')
                           ->setParameter('scopeUser', $user)
                           ->setParameter('userUnit', $userUnit);
                    }
                    break;

                case 'completed':
                    $qb->andWhere('dc.statusCode IN (\'COMPLETED\', \'CLOSED\', \'VALIDATED\', \'RELEASED\', \'ARCHIVED\') OR dc.closedAt IS NOT NULL OR dc.archivedAt IS NOT NULL');
                    break;
            }
        }
    }

    /**
     * Compte les dossiers dont l'échéance est dépassée et non clôturés (Module 8)
     */
    public function countOverdue(?OrganizationUnit $unit = null, ?User $user = null): int
    {
        $qb = $this->createQueryBuilder('dc')
            ->select('COUNT(dc.id)')
            ->where('dc.deadline IS NOT NULL')
            ->andWhere('dc.deadline < :now')
            ->andWhere('dc.closedAt IS NULL')
            ->andWhere('dc.archivedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable());

        if ($unit !== null) {
            $ids = [$unit->getId()];
            foreach ($unit->getAllDescendants() as $desc) {
                if ($desc->getId() !== null) {
                    $ids[] = $desc->getId();
                }
            }
            $qb->andWhere('dc.currentStructure IN (:unitIds)')
               ->setParameter('unitIds', $ids);
        } elseif ($user !== null) {
            $this->applyCriteria($qb, ['current_user' => $user]);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
