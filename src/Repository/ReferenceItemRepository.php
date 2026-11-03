<?php

namespace App\Repository;

use App\Entity\ReferenceItem;
use App\Enum\ReferenceCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReferenceItem>
 */
class ReferenceItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReferenceItem::class);
    }

    /**
     * @return list<ReferenceItem>
     */
    public function findByCategory(ReferenceCategory $category, bool $onlyActive = false): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.category = :category')
            ->setParameter('category', $category)
            ->orderBy('r.displayOrder', 'ASC')
            ->addOrderBy('r.label', 'ASC');

        if ($onlyActive) {
            $qb->andWhere('r.active = :active')
               ->setParameter('active', true);
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByCategoryAndCode(ReferenceCategory $category, string $code): ?ReferenceItem
    {
        return $this->findOneBy([
            'category' => $category,
            'code' => strtoupper(trim($code)),
        ]);
    }
}
