<?php

namespace App\Repository;

use App\Entity\WorkflowStep;
use App\Entity\WorkflowTransition;
use App\Enum\WorkflowAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkflowTransition>
 */
class WorkflowTransitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowTransition::class);
    }

    /**
     * @return list<WorkflowTransition>
     */
    public function findBySourceStep(WorkflowStep $step): array
    {
        /** @var list<WorkflowTransition> */
        return $this->findBy(['sourceStep' => $step, 'active' => true]);
    }

    public function findTransition(WorkflowStep $sourceStep, WorkflowAction $action): ?WorkflowTransition
    {
        return $this->findOneBy([
            'sourceStep' => $sourceStep,
            'action' => $action,
            'active' => true,
        ]);
    }
}
