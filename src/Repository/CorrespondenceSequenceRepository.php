<?php

namespace App\Repository;

use App\Entity\CorrespondenceSequence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CorrespondenceSequence>
 */
class CorrespondenceSequenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CorrespondenceSequence::class);
    }
}
