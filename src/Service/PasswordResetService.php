<?php

namespace App\Service;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
    }

    /**
     * @return array{0: PasswordResetToken, 1: string}
     */
    public function issue(User $user): array
    {
        // Invalidate any existing unused tokens for this user
        $now = new \DateTimeImmutable();
        $this->entityManager->createQuery(
            'UPDATE App\Entity\PasswordResetToken token SET token.usedAt = :now WHERE token.user = :user AND token.usedAt IS NULL'
        )
        ->setParameter('now', $now)
        ->setParameter('user', $user)
        ->execute();

        $plainToken = bin2hex(random_bytes(32));
        $token = new PasswordResetToken(
            $user,
            hash('sha256', $plainToken),
            new \DateTimeImmutable('+30 minutes')
        );

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return [$token, $plainToken];
    }

    public function findValidToken(string $plainToken): ?PasswordResetToken
    {
        if (trim($plainToken) === '') {
            return null;
        }

        $tokenHash = hash('sha256', trim($plainToken));
        $token = $this->entityManager->getRepository(PasswordResetToken::class)->findOneBy(['tokenHash' => $tokenHash]);

        if ($token instanceof PasswordResetToken && $token->isUsable()) {
            return $token;
        }

        return null;
    }

    public function reset(PasswordResetToken $token, string $plainToken, string $newPassword): bool
    {
        if (!$token->isUsable() || !$token->matches($plainToken)) {
            return false;
        }

        $user = $token->getUser();
        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $token->consume();
        $this->entityManager->flush();

        return true;
    }
}
