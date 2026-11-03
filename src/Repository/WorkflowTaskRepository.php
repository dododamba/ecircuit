<?php

namespace App\Repository;

use App\Entity\OrganizationUnit;
use App\Entity\User;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowTask;
use App\Enum\WorkflowTaskStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkflowTask>
 */
class WorkflowTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowTask::class);
    }

    /**
     * @return list<WorkflowTask>
     */
    public function findPendingTasksForUser(User $user): array
    {
        $qb = $this->createQueryBuilder('t')
            ->join('t.instance', 'i')->addSelect('i')
            ->join('i.case', 'c')->addSelect('c')
            ->where('t.status IN (:activeStatuses)')
            ->setParameter('activeStatuses', [WorkflowTaskStatus::Pending, WorkflowTaskStatus::InProgress]);

        if ($user->getOrganizationUnit() !== null) {
            $qb->andWhere('t.targetUser = :user OR (t.targetUser IS NULL AND t.targetStructure = :structure)')
               ->setParameter('user', $user)
               ->setParameter('structure', $user->getOrganizationUnit());
        } else {
            $qb->andWhere('t.targetUser = :user')
               ->setParameter('user', $user);
        }

        /** @var list<WorkflowTask> */
        return $qb->orderBy('t.createdAt', 'DESC')->getQuery()->getResult();
    }
}
