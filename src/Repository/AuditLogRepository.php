<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * @return list<AuditLog>
     */
    public function findRecent(int $limit = 100, ?string $event = null, ?User $user = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')
            ->addSelect('u')
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit);

        if ($event !== null && trim($event) !== '') {
            $eventUpper = strtoupper(trim($event));
            $qb->andWhere('a.action = :event OR a.event = :event')
               ->setParameter('event', $eventUpper);
        }

        if ($user !== null) {
            $qb->andWhere('a.user = :user')
               ->setParameter('user', $user);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Recherche multicritère paginée sans problème N+1.
     *
     * @param array<string, mixed> $filters
     * @return list<AuditLog>
     */
    public function findByFilters(array $filters, int $limit = 30, int $offset = 0, string $sortDirection = 'DESC'): array
    {
        $direction = strtoupper($sortDirection) === 'ASC' ? 'ASC' : 'DESC';

        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')
            ->addSelect('u')
            ->leftJoin('u.organizationUnit', 'ou')
            ->addSelect('ou')
            ->orderBy('a.createdAt', $direction)
            ->addOrderBy('a.id', $direction)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(200, $limit)));

        $this->applyFilters($qb, $filters);

        return $qb->getQuery()->getResult();
    }

    /**
     * Compte le nombre total d'entrées correspondant aux filtres.
     *
     * @param array<string, mixed> $filters
     */
    public function countByFilters(array $filters): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(DISTINCT a.id)')
            ->leftJoin('a.user', 'u');

        $this->applyFilters($qb, $filters);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Statistiques synthétiques pour le tableau de bord d'audit.
     *
     * @return array{total: int, success: int, failure: int, denied: int}
     */
    public function getSummaryStats(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.result AS result, COUNT(a.id) AS cnt')
            ->groupBy('a.result')
            ->getQuery()
            ->getArrayResult();

        $stats = [
            'total' => 0,
            'success' => 0,
            'failure' => 0,
            'denied' => 0,
        ];

        foreach ($rows as $row) {
            $cnt = (int) $row['cnt'];
            $res = strtoupper((string) $row['result']);
            $stats['total'] += $cnt;
            if ($res === 'SUCCESS') {
                $stats['success'] += $cnt;
            } elseif ($res === 'DENIED') {
                $stats['denied'] += $cnt;
            } else {
                $stats['failure'] += $cnt;
            }
        }

        return $stats;
    }

    /**
     * Liste distincte des types d'entités présents dans les journaux d'audit.
     *
     * @return list<string>
     */
    public function findDistinctEntityTypes(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('DISTINCT a.entityType AS entityType')
            ->where('a.entityType IS NOT NULL')
            ->orderBy('a.entityType', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_values(array_filter(array_map(fn ($r) => (string) $r['entityType'], $rows)));
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['user_id'])) {
            $qb->andWhere('IDENTITY(a.user) = :userId')
               ->setParameter('userId', (int) $filters['user_id']);
        }

        if (!empty($filters['structure_id'])) {
            $qb->andWhere('IDENTITY(u.organizationUnit) = :structureId')
               ->setParameter('structureId', (int) $filters['structure_id']);
        }

        if (!empty($filters['role_id'])) {
            $qb->innerJoin('u.accessRoles', 'ar')
               ->andWhere('ar.id = :roleId')
               ->setParameter('roleId', (int) $filters['role_id']);
        }

        if (!empty($filters['action'])) {
            $action = strtoupper(trim((string) $filters['action']));
            $qb->andWhere('(a.action = :action OR a.event = :action)')
               ->setParameter('action', $action);
        }

        if (!empty($filters['entity_type'])) {
            $qb->andWhere('LOWER(a.entityType) = :entityType')
               ->setParameter('entityType', strtolower(trim((string) $filters['entity_type'])));
        }

        if (isset($filters['entity_id']) && trim((string) $filters['entity_id']) !== '') {
            $qb->andWhere('a.entityId = :entityId')
               ->setParameter('entityId', trim((string) $filters['entity_id']));
        }

        if (!empty($filters['result'])) {
            $result = strtoupper(trim((string) $filters['result']));
            $qb->andWhere('a.result = :result')
               ->setParameter('result', $result);
        }

        if (!empty($filters['ip'])) {
            $qb->andWhere('a.ipAddress LIKE :ip')
               ->setParameter('ip', '%' . trim((string) $filters['ip']) . '%');
        }

        if (!empty($filters['route'])) {
            $qb->andWhere('a.route LIKE :route')
               ->setParameter('route', '%' . trim((string) $filters['route']) . '%');
        }

        if (!empty($filters['date_from'])) {
            try {
                $from = $filters['date_from'] instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($filters['date_from'])->setTime(0, 0, 0)
                    : (new \DateTimeImmutable((string) $filters['date_from']))->setTime(0, 0, 0);
                $qb->andWhere('a.createdAt >= :dateFrom')
                   ->setParameter('dateFrom', $from);
            } catch (\Throwable) {
            }
        }

        if (!empty($filters['date_to'])) {
            try {
                $to = $filters['date_to'] instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($filters['date_to'])->setTime(23, 59, 59)
                    : (new \DateTimeImmutable((string) $filters['date_to']))->setTime(23, 59, 59);
                $qb->andWhere('a.createdAt <= :dateTo')
                   ->setParameter('dateTo', $to);
            } catch (\Throwable) {
            }
        }

        if (!empty($filters['q'])) {
            $q = '%' . strtolower(trim((string) $filters['q'])) . '%';
            $qb->andWhere('(LOWER(a.action) LIKE :q OR LOWER(a.event) LIKE :q OR LOWER(a.entityType) LIKE :q OR LOWER(a.entityId) LIKE :q OR LOWER(a.route) LIKE :q OR LOWER(a.ipAddress) LIKE :q OR LOWER(u.email) LIKE :q OR LOWER(u.firstName) LIKE :q OR LOWER(u.lastName) LIKE :q)')
               ->setParameter('q', $q);
        }
    }
}

