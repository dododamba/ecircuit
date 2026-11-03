<?php

namespace App\Repository;

use App\Entity\OrganizationUnit;
use App\Enum\StructureType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizationUnit>
 */
class OrganizationUnitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationUnit::class);
    }

    /**
     * @return list<OrganizationUnit>
     */
    public function findRootUnits(): array
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.children', 'c')
            ->addSelect('c')
            ->where('o.parent IS NULL')
            ->orderBy('o.displayOrder', 'ASC')
            ->addOrderBy('o.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<OrganizationUnit>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.parent', 'p')
            ->addSelect('p')
            ->orderBy('o.displayOrder', 'ASC')
            ->addOrderBy('o.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<OrganizationUnit>
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.active = :active')
            ->setParameter('active', true)
            ->orderBy('o.displayOrder', 'ASC')
            ->addOrderBy('o.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByCode(string $code): ?OrganizationUnit
    {
        return $this->findOneBy(['code' => strtoupper(trim($code))]);
    }
}
