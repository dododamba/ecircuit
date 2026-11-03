<?php

namespace App\Repository;

use App\Entity\Sender;
use App\Enum\SenderType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Sender>
 */
class SenderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sender::class);
    }

    /**
     * Recherche d'expéditeurs pour auto-complétion
     *
     * @return list<Sender>
     */
    public function search(string $term, int $limit = 20): array
    {
        $clean = trim($term);
        if ($clean === '') {
            return $this->findBy([], ['name' => 'ASC'], $limit);
        }

        /** @var list<Sender> */
        return $this->createQueryBuilder('s')
            ->where('s.name LIKE :term OR s.organisation LIKE :term OR s.email LIKE :term')
            ->setParameter('term', '%' . $clean . '%')
            ->orderBy('s.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche un expéditeur existant ou en crée un nouveau
     */
    public function findOrCreate(
        SenderType $type,
        string $name,
        ?string $organisation = null,
        ?string $phone = null,
        ?string $email = null,
        ?string $address = null
    ): Sender {
        $cleanName = trim($name);
        $cleanEmail = $email ? strtolower(trim($email)) : null;

        $sender = null;
        if ($cleanEmail !== null) {
            $sender = $this->findOneBy(['email' => $cleanEmail]);
        }

        if ($sender === null) {
            $sender = $this->findOneBy(['name' => $cleanName, 'type' => $type]);
        }

        if ($sender === null) {
            $sender = new Sender($type, $cleanName);
            $this->getEntityManager()->persist($sender);
        }

        if ($organisation !== null && $organisation !== '') {
            $sender->setOrganisation($organisation);
        }
        if ($phone !== null && $phone !== '') {
            $sender->setPhone($phone);
        }
        if ($cleanEmail !== null && $cleanEmail !== '') {
            $sender->setEmail($cleanEmail);
        }
        if ($address !== null && $address !== '') {
            $sender->setAddress($address);
        }

        return $sender;
    }
}
