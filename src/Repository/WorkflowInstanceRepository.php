<?php

namespace App\Repository;

use App\Entity\DossierCase;
use App\Entity\WorkflowInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkflowInstance>
 */
class WorkflowInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowInstance::class);
    }

    public function findByCase(DossierCase $case): ?WorkflowInstance
    {
        return $this->findOneBy(['case' => $case]);
    }
}
