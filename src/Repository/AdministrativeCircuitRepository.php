<?php

namespace App\Repository;

use App\Entity\AdministrativeCircuit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdministrativeCircuit>
 */
class AdministrativeCircuitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdministrativeCircuit::class);
    }

    public function findByCode(string $code): ?AdministrativeCircuit
    {
        return $this->findOneBy(['code' => strtoupper(trim($code))]);
    }

    /**
     * @return list<AdministrativeCircuit>
     */
    public function findAllWithSteps(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.steps', 's')
            ->leftJoin('s.organizationUnit', 'u')
            ->addSelect('s', 'u')
            ->orderBy('c.label', 'ASC')
            ->addOrderBy('s.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
