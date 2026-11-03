<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/** @extends ServiceEntityRepository<User> */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface, UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, User::class); }
    public function loadUserByIdentifier(string $identifier): ?User
    {
        return $this->findOneBy(['email' => strtolower($identifier)]);
    }
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) { return; }
        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    /**
     * @return list<User>
     */
    public function findBySearchAndFilters(?string $query = null, ?string $status = null, ?int $roleId = null, ?int $structureId = null): array
    {
        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.accessRoles', 'r')
            ->leftJoin('u.organizationUnit', 'o')
            ->addSelect('r', 'o')
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC');

        if ($query !== null && trim($query) !== '') {
            $term = '%'.strtolower(trim($query)).'%';
            $qb->andWhere('LOWER(u.email) LIKE :term OR LOWER(u.firstName) LIKE :term OR LOWER(u.lastName) LIKE :term OR LOWER(u.username) LIKE :term')
               ->setParameter('term', $term);
        }

        if ($status !== null && trim($status) !== '') {
            $qb->andWhere('u.status = :status')
               ->setParameter('status', $status);
        }

        if ($roleId !== null && $roleId > 0) {
            $qb->andWhere(':role MEMBER OF u.accessRoles')
               ->setParameter('role', $roleId);
        }

        if ($structureId !== null && $structureId > 0) {
            $qb->andWhere('u.organizationUnit = :structure')
               ->setParameter('structure', $structureId);
        }

        return $qb->getQuery()->getResult();
    }
}
