<?php

namespace App\Repository;

use App\Entity\Correspondence;
use App\Entity\User;
use App\Enum\CorrespondenceStatus;
use App\Enum\EntryChannel;
use App\Service\AccessScopeService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Correspondence>
 */
class CorrespondenceRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly AccessScopeService $accessScopeService
    ) {
        parent::__construct($registry, Correspondence::class);
    }

    /**
     * Recherche avancée et filtrage des courriers avec respect du cloisonnement organisationnel
     *
     * @param array<string, mixed> $filters
     * @return list<Correspondence>
     */
    public function findByFilters(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.sender', 's')->addSelect('s')
            ->leftJoin('c.priority', 'p')->addSelect('p')
            ->leftJoin('c.documentType', 'dt')->addSelect('dt')
            ->leftJoin('c.mailType', 'mt')->addSelect('mt')
            ->leftJoin('c.case', 'dcase')->addSelect('dcase')
            ->orderBy('c.receivedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $this->applyCriteria($qb, $filters);

        /** @var list<Correspondence> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Compte total des courriers selon filtres
     *
     * @param array<string, mixed> $filters
     */
    public function countByFilters(array $filters = []): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(DISTINCT c.id)')
            ->leftJoin('c.sender', 's')
            ->leftJoin('c.priority', 'p')
            ->leftJoin('c.documentType', 'dt')
            ->leftJoin('c.mailType', 'mt')
            ->leftJoin('c.case', 'dcase');

        $this->applyCriteria($qb, $filters);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyCriteria(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['direction'])) {
            $dir = strtoupper(trim((string) $filters['direction']));
            if ($dir === 'OUTGOING') {
                $qb->andWhere('c.reference LIKE :outPrefix OR mt.code = :outMailCode')
                   ->setParameter('outPrefix', 'MCI-OUT-%')
                   ->setParameter('outMailCode', 'COURRIER_SORTANT');
            } elseif ($dir === 'INCOMING') {
                $qb->andWhere('(c.reference IS NULL OR c.reference NOT LIKE :outPrefix) AND (mt.code IS NULL OR mt.code != :outMailCode)')
                   ->setParameter('outPrefix', 'MCI-OUT-%')
                   ->setParameter('outMailCode', 'COURRIER_SORTANT');
            }
        }

        if (!empty($filters['search'])) {
            $search = '%' . trim((string) $filters['search']) . '%';
            $qb->andWhere('c.reference LIKE :search OR c.subject LIKE :search OR s.name LIKE :search OR s.organisation LIKE :search')
               ->setParameter('search', $search);
        }

        if (!empty($filters['channel'])) {
            $channelEnum = EntryChannel::tryFrom((string) $filters['channel']);
            if ($channelEnum) {
                $qb->andWhere('c.entryChannel = :channel')
                   ->setParameter('channel', $channelEnum);
            }
        }

        if (!empty($filters['priority'])) {
            $qb->andWhere('p.code = :priority OR p.id = :priorityId')
               ->setParameter('priority', strtoupper((string) $filters['priority']))
               ->setParameter('priorityId', (int) $filters['priority']);
        }

        if (!empty($filters['document_type'])) {
            $docTypeVal = trim((string) $filters['document_type']);
            $qb->andWhere('UPPER(dt.code) = :docTypeCode OR dt.id = :docTypeId')
               ->setParameter('docTypeCode', strtoupper($docTypeVal))
               ->setParameter('docTypeId', is_numeric($docTypeVal) ? (int) $docTypeVal : -1);
        }

        if (!empty($filters['status'])) {
            $statusEnum = CorrespondenceStatus::tryFrom((string) $filters['status']);
            if ($statusEnum) {
                $qb->andWhere('c.status = :status')
                   ->setParameter('status', $statusEnum);
            }
        }

        if (!empty($filters['dateFrom'])) {
            try {
                $from = new \DateTimeImmutable($filters['dateFrom'] . ' 00:00:00');
                $qb->andWhere('c.receivedAt >= :dateFrom')->setParameter('dateFrom', $from);
            } catch (\Throwable) {}
        }

        if (!empty($filters['dateTo'])) {
            try {
                $to = new \DateTimeImmutable($filters['dateTo'] . ' 23:59:59');
                $qb->andWhere('c.receivedAt <= :dateTo')->setParameter('dateTo', $to);
            } catch (\Throwable) {}
        }

        /** @var User|null $user */
        $user = $filters['current_user'] ?? null;
        if ($user instanceof User && !$this->accessScopeService->hasGlobalVisibility($user)) {
            $accessibleIds = $this->accessScopeService->getAccessibleStructureIds($user);
            if (!empty($accessibleIds)) {
                $qb->andWhere(
                    '(c.registeredBy = :accessScopeUser ' .
                    'OR c.validatedBy = :accessScopeUser ' .
                    'OR dcase.currentAssignee = :accessScopeUser ' .
                    'OR dcase.currentStructure IN (:accessibleStructureIds) ' .
                    'OR EXISTS (SELECT 1 FROM App\Entity\CaseTransmission ct_acc WHERE ct_acc.case = dcase AND (ct_acc.transmittedBy = :accessScopeUser OR ct_acc.sourceUnit IN (:accessibleStructureIds) OR ct_acc.targetUnit IN (:accessibleStructureIds))) ' .
                    'OR EXISTS (SELECT 1 FROM App\Entity\CaseEvent ce_acc WHERE ce_acc.case = dcase AND (ce_acc.actor = :accessScopeUser OR ce_acc.organizationUnit IN (:accessibleStructureIds))))'
                )
                   ->setParameter('accessScopeUser', $user)
                   ->setParameter('accessibleStructureIds', $accessibleIds);
            } else {
                $qb->andWhere('(c.registeredBy = :accessScopeUser OR c.validatedBy = :accessScopeUser OR dcase.currentAssignee = :accessScopeUser OR dcase IS NULL)')
                   ->setParameter('accessScopeUser', $user);
            }
        }
    }

    /**
     * Statistiques globales du courrier pour le tableau de bord
     *
     * @return array{total: int, urgent: int, today: int, registered: int}
     */
    public function getSummaryStats(?User $user = null): array
    {
        $todayStart = (new \DateTimeImmutable())->setTime(0, 0, 0);

        $total = $this->countByFilters(['current_user' => $user]);

        $qbUrgent = $this->createQueryBuilder('c')
            ->select('COUNT(DISTINCT c.id)')
            ->leftJoin('c.priority', 'p')
            ->leftJoin('c.case', 'dcase')
            ->where('p.code IN (:urgents)')
            ->setParameter('urgents', ['URGENTE', 'TRES_URGENTE', 'URGENT']);
        $this->applyCriteria($qbUrgent, ['current_user' => $user]);
        $urgent = (int) $qbUrgent->getQuery()->getSingleScalarResult();

        $qbToday = $this->createQueryBuilder('c')
            ->select('COUNT(DISTINCT c.id)')
            ->leftJoin('c.case', 'dcase')
            ->where('c.createdAt >= :today')
            ->setParameter('today', $todayStart);
        $this->applyCriteria($qbToday, ['current_user' => $user]);
        $today = (int) $qbToday->getQuery()->getSingleScalarResult();

        $qbReg = $this->createQueryBuilder('c')
            ->select('COUNT(DISTINCT c.id)')
            ->leftJoin('c.case', 'dcase')
            ->where('c.status = :status')
            ->setParameter('status', CorrespondenceStatus::Validated);
        $this->applyCriteria($qbReg, ['current_user' => $user]);
        $registered = (int) $qbReg->getQuery()->getSingleScalarResult();

        return [
            'total' => $total,
            'urgent' => $urgent,
            'today' => $today,
            'registered' => $registered,
        ];
    }
}
