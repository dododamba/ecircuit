<?php

namespace App\Entity;

use App\Enum\SenderType;
use App\Repository\SenderRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SenderRepository::class)]
#[ORM\Table(name: 'senders')]
class Sender
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: SenderType::class)]
    private SenderType $type;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $organisation = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(SenderType $type, string $name)
    {
        $this->type = $type;
        $this->name = trim($name);
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getType(): SenderType { return $this->type; }
    public function setType(SenderType $type): void { $this->type = $type; $this->updatedAt = new \DateTimeImmutable(); }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = trim($name); $this->updatedAt = new \DateTimeImmutable(); }
    public function getOrganisation(): ?string { return $this->organisation; }
    public function getOrganizationName(): ?string { return $this->organisation; }
    public function setOrganisation(?string $organisation): void { $this->organisation = $organisation ? trim($organisation) : null; $this->updatedAt = new \DateTimeImmutable(); }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): void { $this->phone = $phone ? trim($phone) : null; $this->updatedAt = new \DateTimeImmutable(); }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): void { $this->email = $email ? strtolower(trim($email)) : null; $this->updatedAt = new \DateTimeImmutable(); }
    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $address): void { $this->address = $address ? trim($address) : null; $this->updatedAt = new \DateTimeImmutable(); }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): void { $this->notes = $notes ? trim($notes) : null; $this->updatedAt = new \DateTimeImmutable(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getDisplayName(): string
    {
        if ($this->organisation) {
            return $this->name . ' (' . $this->organisation . ')';
        }
        return $this->name;
    }
}
