<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'case_transmissions')]
class CaseTransmission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DossierCase::class, inversedBy: 'transmissions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DossierCase $case;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?OrganizationUnit $sourceUnit = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?OrganizationUnit $targetUnit = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $transmittedBy = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $instructions = null;

    #[ORM\Column]
    private \DateTimeImmutable $transmittedAt;

    public function __construct(
        DossierCase $case,
        ?OrganizationUnit $sourceUnit,
        ?OrganizationUnit $targetUnit,
        ?User $transmittedBy = null,
        ?string $reason = null,
        ?string $instructions = null
    ) {
        $this->case = $case;
        $this->sourceUnit = $sourceUnit;
        $this->targetUnit = $targetUnit;
        $this->transmittedBy = $transmittedBy;
        $this->reason = $reason;
        $this->instructions = $instructions;
        $this->transmittedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getCase(): DossierCase { return $this->case; }
    public function setCase(DossierCase $case): void { $this->case = $case; }

    public function getSourceUnit(): ?OrganizationUnit { return $this->sourceUnit; }
    public function setSourceUnit(?OrganizationUnit $sourceUnit): void { $this->sourceUnit = $sourceUnit; }

    public function getTargetUnit(): ?OrganizationUnit { return $this->targetUnit; }
    public function setTargetUnit(?OrganizationUnit $targetUnit): void { $this->targetUnit = $targetUnit; }

    public function getTransmittedBy(): ?User { return $this->transmittedBy; }
    public function setTransmittedBy(?User $transmittedBy): void { $this->transmittedBy = $transmittedBy; }

    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): void { $this->reason = $reason; }

    public function getInstructions(): ?string { return $this->instructions; }
    public function setInstructions(?string $instructions): void { $this->instructions = $instructions; }

    public function getTransmittedAt(): \DateTimeImmutable { return $this->transmittedAt; }
}
