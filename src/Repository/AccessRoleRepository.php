<?php

namespace App\Repository;

use App\Entity\AccessRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccessRole>
 */
class AccessRoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessRole::class);
    }

    public function findByCode(string $code): ?AccessRole
    {
        return $this->findOneBy(['code' => strtoupper(trim($code))]);
    }

    /**
     * @return list<AccessRole>
     */
    public function findAllWithPermissions(): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.permissions', 'p')
            ->addSelect('p')
            ->orderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
