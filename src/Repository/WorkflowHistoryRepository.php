<?php

namespace App\Repository;

use App\Entity\WorkflowHistory;
use App\Entity\WorkflowInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkflowHistory>
 */
class WorkflowHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowHistory::class);
    }

    /**
     * @return list<WorkflowHistory>
     */
    public function findByInstance(WorkflowInstance $instance): array
    {
        /** @var list<WorkflowHistory> */
        return $this->findBy(['instance' => $instance], ['occurredAt' => 'ASC']);
    }
}
