<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'workflow_steps')]
#[ORM\UniqueConstraint(name: 'workflow_step_code_unique', columns: ['workflow_id', 'code'])]
#[ORM\UniqueConstraint(name: 'workflow_step_pos_unique', columns: ['workflow_id', 'position'])]
class WorkflowStep
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'steps')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workflow $workflow;

    #[ORM\Column(length: 100)]
    private string $code;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\Column]
    private int $position;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?OrganizationUnit $targetStructure = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AccessRole $targetRole = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isInitial = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $isFinal = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(nullable: true)]
    private ?int $standardDurationHours = null;

    public function __construct(
        Workflow $workflow,
        string $code,
        string $name,
        int $position,
        ?OrganizationUnit $targetStructure = null,
        ?AccessRole $targetRole = null,
        bool $isInitial = false,
        bool $isFinal = false
    ) {
        $this->workflow = $workflow;
        $this->code = strtoupper(trim($code));
        $this->name = trim($name);
        $this->position = $position;
        $this->targetStructure = $targetStructure;
        $this->targetRole = $targetRole;
        $this->isInitial = $isInitial;
        $this->isFinal = $isFinal;
        $workflow->addStep($this);
    }

    public function getId(): ?int { return $this->id; }

    public function getWorkflow(): Workflow { return $this->workflow; }
    public function setWorkflow(Workflow $workflow): void { $this->workflow = $workflow; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): void { $this->code = strtoupper(trim($code)); }

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = trim($name); }

    public function getPosition(): int { return $this->position; }
    public function getOrder(): int { return $this->position; }
    public function setPosition(int $position): void { $this->position = $position; }

    public function getTargetStructure(): ?OrganizationUnit { return $this->targetStructure; }
    public function setTargetStructure(?OrganizationUnit $targetStructure): void { $this->targetStructure = $targetStructure; }

    public function getTargetRole(): ?AccessRole { return $this->targetRole; }
    public function setTargetRole(?AccessRole $targetRole): void { $this->targetRole = $targetRole; }

    public function isInitial(): bool { return $this->isInitial; }
    public function setIsInitial(bool $isInitial): void { $this->isInitial = $isInitial; }

    public function isFinal(): bool { return $this->isFinal; }
    public function setIsFinal(bool $isFinal): void { $this->isFinal = $isFinal; }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): void { $this->active = $active; }

    public function getStandardDurationHours(): ?int { return $this->standardDurationHours; }
    public function setStandardDurationHours(?int $hours): void { $this->standardDurationHours = $hours; }
}
