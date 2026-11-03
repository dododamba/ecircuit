<?php

namespace App\Repository;

use App\Entity\AccessPermission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccessPermission>
 */
class AccessPermissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessPermission::class);
    }

    public function findByCode(string $code): ?AccessPermission
    {
        return $this->findOneBy(['code' => strtoupper(trim($code))]);
    }

    /**
     * @return array<string, list<AccessPermission>>
     */
    public function findGroupedByModule(): array
    {
        $permissions = $this->findBy([], ['module' => 'ASC', 'code' => 'ASC']);
        $grouped = [];
        foreach ($permissions as $perm) {
            $grouped[$perm->getModule()][] = $perm;
        }
        return $grouped;
    }
}
