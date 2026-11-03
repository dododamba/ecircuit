<?php

namespace App\Repository;

use App\Entity\CaseEvent;
use App\Entity\DossierCase;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CaseEvent>
 */
class CaseEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CaseEvent::class);
    }

    /**
     * Recherche paginée des événements d'un dossier avec filtres fonctionnels
     *
     * @return list<CaseEvent>
     */
    public function findByCaseWithFilters(
        DossierCase $case,
        ?string $category = 'all',
        ?string $action = null,
        string $order = 'ASC',
        int $limit = 50,
        int $offset = 0
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.actor', 'u')
            ->addSelect('u')
            ->leftJoin('e.organizationUnit', 'o')
            ->addSelect('o')
            ->andWhere('e.case = :case')
            ->setParameter('case', $case);

        $this->applyCategoryFilter($qb, $category);

        if ($action !== null && $action !== '') {
            $qb->andWhere('e.action = :action OR e.eventType = :action')
                ->setParameter('action', $action);
        }

        $orderDirection = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
        $qb->orderBy('e.occurredAt', $orderDirection)
            ->addOrderBy('e.id', $orderDirection);

        if ($limit > 0) {
            $qb->setMaxResults($limit)->setFirstResult($offset);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Comptage des événements pour la pagination
     */
    public function countByCaseWithFilters(
        DossierCase $case,
        ?string $category = 'all',
        ?string $action = null
    ): int {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.case = :case')
            ->setParameter('case', $case);

        $this->applyCategoryFilter($qb, $category);

        if ($action !== null && $action !== '') {
            $qb->andWhere('e.action = :action OR e.eventType = :action')
                ->setParameter('action', $action);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Retourne le nombre d'événements par catégorie pour les pastilles de filtre
     *
     * @return array<string, int>
     */
    public function getCategoryCounts(DossierCase $case): array
    {
        return [
            'all' => $this->countByCaseWithFilters($case, 'all'),
            'workflow' => $this->countByCaseWithFilters($case, 'workflow'),
            'documents' => $this->countByCaseWithFilters($case, 'documents'),
            'affectations' => $this->countByCaseWithFilters($case, 'affectations'),
            'validations' => $this->countByCaseWithFilters($case, 'validations'),
        ];
    }

    /**
     * Applique les filtres de catégories fonctionnelles (Module 9 - Section 5)
     */
    private function applyCategoryFilter(QueryBuilder $qb, ?string $category): void
    {
        if ($category === null || $category === '' || $category === 'all') {
            return;
        }

        switch (strtolower(trim($category))) {
            case 'workflow':
                $qb->andWhere('e.action IN (:wfActions) OR e.eventType IN (:wfTypes)')
                    ->setParameter('wfActions', [
                        'SUBMIT', 'SUBMITTED', 'ORIENT', 'ORIENTED', 'FORWARD', 'FORWARDED',
                        'PROCESS', 'PROCESSED', 'RETURN', 'RETURNED', 'REJECT', 'REJECTED',
                        'RELEASE', 'RELEASED', 'NOTE_ADDED', 'ANNOTATE', 'ANNOTATED'
                    ])
                    ->setParameter('wfTypes', [
                        'SUBMITTED', 'ORIENTED', 'FORWARDED', 'CASE_FORWARDED', 'PROCESSED',
                        'RETURNED', 'REJECTED', 'RELEASED', 'CASE_UPDATED', 'CASE_RECEIVED',
                        'CASE_IN_PROGRESS', 'CASE_PENDING', 'NOTE_ADDED', 'ANNOTATED'
                    ]);
                break;

            case 'documents':
                $qb->andWhere('e.action IN (:docActions) OR e.eventType IN (:docTypes)')
                    ->setParameter('docActions', ['DOCUMENT_ADDED', 'DOCUMENT_DOWNLOADED'])
                    ->setParameter('docTypes', ['DOCUMENT_ADDED', 'DOCUMENT_DOWNLOADED']);
                break;

            case 'affectations':
                $qb->andWhere('e.action IN (:affActions) OR e.eventType IN (:affTypes)')
                    ->setParameter('affActions', ['ASSIGN', 'ASSIGNED', 'TAKE_IN_CHARGE', 'TAKEN_IN_CHARGE', 'DOSSIER_AFFECTE'])
                    ->setParameter('affTypes', ['ASSIGNED', 'CASE_ASSIGNED', 'DOSSIER_AFFECTE', 'TAKEN_IN_CHARGE']);
                break;

            case 'validations':
                $qb->andWhere('e.action IN (:valActions) OR e.eventType IN (:valTypes)')
                    ->setParameter('valActions', ['VALIDATE', 'VALIDATED', 'CLOSE', 'COMPLETED'])
                    ->setParameter('valTypes', ['VALIDATED', 'CASE_VALIDATED', 'COMPLETED', 'CASE_CLOSED']);
                break;
        }
    }
}
