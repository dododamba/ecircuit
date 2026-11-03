<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'workflows')]
class Workflow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $code;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** @var Collection<int, WorkflowStep> */
    #[ORM\OneToMany(targetEntity: WorkflowStep::class, mappedBy: 'workflow', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $steps;

    /** @var Collection<int, WorkflowTransition> */
    #[ORM\OneToMany(targetEntity: WorkflowTransition::class, mappedBy: 'workflow', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $transitions;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $name, ?string $description = null)
    {
        $this->code = strtoupper(trim($code));
        $this->name = trim($name);
        $this->description = $description ? trim($description) : null;
        $this->steps = new ArrayCollection();
        $this->transitions = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): void { $this->code = strtoupper(trim($code)); $this->updatedAt = new \DateTimeImmutable(); }

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = trim($name); $this->updatedAt = new \DateTimeImmutable(); }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): void { $this->description = $description ? trim($description) : null; $this->updatedAt = new \DateTimeImmutable(); }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): void { $this->active = $active; $this->updatedAt = new \DateTimeImmutable(); }

    /** @return Collection<int, WorkflowStep> */
    public function getSteps(): Collection { return $this->steps; }

    /** @return list<WorkflowStep> */
    public function getActiveSteps(): array
    {
        return array_values($this->steps->filter(fn(WorkflowStep $step) => $step->isActive())->toArray());
    }

    public function addStep(WorkflowStep $step): void
    {
        if (!$this->steps->contains($step)) {
            $this->steps->add($step);
            $step->setWorkflow($this);
        }
    }

    public function removeStep(WorkflowStep $step): void
    {
        $this->steps->removeElement($step);
    }

    public function getInitialStep(): ?WorkflowStep
    {
        foreach ($this->steps as $step) {
            if ($step->isInitial() && $step->isActive()) {
                return $step;
            }
        }
        return $this->steps->first() ?: null;
    }

    public function getStepByCode(string $code): ?WorkflowStep
    {
        $target = strtoupper(trim($code));
        foreach ($this->steps as $step) {
            if ($step->getCode() === $target) {
                return $step;
            }
        }
        return null;
    }

    /** @return Collection<int, WorkflowTransition> */
    public function getTransitions(): Collection { return $this->transitions; }

    public function addTransition(WorkflowTransition $transition): void
    {
        if (!$this->transitions->contains($transition)) {
            $this->transitions->add($transition);
            $transition->setWorkflow($this);
        }
    }

    public function removeTransition(WorkflowTransition $transition): void
    {
        $this->transitions->removeElement($transition);
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
