<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'password_reset_tokens')]
class PasswordResetToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;
    #[ORM\Column(length: 255)]
    private string $tokenHash;
    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
    public function __construct(User $user, string $tokenHash, \DateTimeImmutable $expiresAt) { $this->user = $user; $this->tokenHash = $tokenHash; $this->expiresAt = $expiresAt; $this->createdAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getTokenHash(): string { return $this->tokenHash; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getUsedAt(): ?\DateTimeImmutable { return $this->usedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function matches(string $plainToken): bool { return hash_equals($this->tokenHash, hash('sha256', $plainToken)); }
    public function isUsable(): bool { return $this->usedAt === null && $this->expiresAt > new \DateTimeImmutable(); }
    public function consume(): void { $this->usedAt = new \DateTimeImmutable(); }
}
