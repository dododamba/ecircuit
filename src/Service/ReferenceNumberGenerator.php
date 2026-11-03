<?php

namespace App\Service;

use App\Entity\CorrespondenceSequence;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class ReferenceNumberGenerator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    /**
     * Génère de manière atomique la référence officielle standardisée : MCI-YYYY-NNNNNN
     * 
     * @param int|null $year Année civile (par défaut l'année en cours)
     * @return string Exemple : MCI-2026-000001
     */
    public function generateNextReference(?int $year = null): string
    {
        $targetYear = $year ?? (int) (new \DateTimeImmutable())->format('Y');

        return $this->entityManager->wrapInTransaction(function () use ($targetYear): string {
            // Tentative de verrouillage pessimiste si la connexion le supporte (MySQL / MariaDB)
            $isSqlite = $this->entityManager->getConnection()->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SqlitePlatform;

            $sequence = null;
            if (!$isSqlite) {
                try {
                    $sequence = $this->entityManager->find(
                        CorrespondenceSequence::class,
                        $targetYear,
                        LockMode::PESSIMISTIC_WRITE
                    );
                } catch (\Throwable) {
                    $sequence = null;
                }
            }

            if ($sequence === null) {
                $sequence = $this->entityManager->find(CorrespondenceSequence::class, $targetYear);
            }

            if ($sequence === null) {
                $sequence = new CorrespondenceSequence($targetYear);
                $this->entityManager->persist($sequence);
            }

            $nextValue = $sequence->nextValue();
            $this->entityManager->flush();

            return sprintf('MCI-%04d-%06d', $targetYear, $nextValue);
        });
    }

    /**
     * Génère de manière atomique la référence officielle d'un courrier sortant : MCI-OUT-YYYY-NNNNNN
     *
     * @param int|null $year Année civile (par défaut l'année en cours)
     * @return string Exemple : MCI-OUT-2026-000001
     */
    public function generateNextOutgoingReference(?int $year = null): string
    {
        $targetYear = $year ?? (int) (new \DateTimeImmutable())->format('Y');

        return $this->entityManager->wrapInTransaction(function () use ($targetYear): string {
            $isSqlite = $this->entityManager->getConnection()->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SqlitePlatform;

            $sequence = null;
            if (!$isSqlite) {
                try {
                    $sequence = $this->entityManager->find(
                        CorrespondenceSequence::class,
                        $targetYear,
                        LockMode::PESSIMISTIC_WRITE
                    );
                } catch (\Throwable) {
                    $sequence = null;
                }
            }

            if ($sequence === null) {
                $sequence = $this->entityManager->find(CorrespondenceSequence::class, $targetYear);
            }

            if ($sequence === null) {
                $sequence = new CorrespondenceSequence($targetYear);
                $this->entityManager->persist($sequence);
            }

            $nextValue = $sequence->nextOutgoingValue();
            $this->entityManager->flush();

            return sprintf('MCI-OUT-%04d-%06d', $targetYear, $nextValue);
        });
    }

    /**
     * Valide la conformité syntaxique d'une référence officielle MCI (courrier entrant ou dossier)
     */
    public static function isValidFormat(string $reference): bool
    {
        return (bool) preg_match('/^MCI-\d{4}-\d{6}$/', trim($reference));
    }

    /**
     * Valide la conformité syntaxique d'une référence officielle de courrier sortant MCI
     */
    public static function isValidOutgoingFormat(string $reference): bool
    {
        return (bool) preg_match('/^MCI-OUT-\d{4}-\d{6}$/', trim($reference));
    }
}
