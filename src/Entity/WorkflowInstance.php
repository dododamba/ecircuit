<?php

namespace App\Entity;

use App\Enum\WorkflowInstanceStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'workflow_instances')]
#[ORM\Index(columns: ['workflow_id'], name: 'idx_instance_workflow')]
#[ORM\Index(columns: ['current_step_id'], name: 'idx_instance_current_step')]
#[ORM\Index(columns: ['status'], name: 'idx_instance_status')]
class WorkflowInstance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: DossierCase::class, inversedBy: 'workflowInstance')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DossierCase $case;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Workflow $workflow;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private WorkflowStep $currentStep;

    #[ORM\Column(length: 40, enumType: WorkflowInstanceStatus::class)]
    private WorkflowInstanceStatus $status = WorkflowInstanceStatus::Active;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column]
    private \DateTimeImmutable $stepEnteredAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $stepDeadline = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @var Collection<int, WorkflowTask> */
    #[ORM\OneToMany(targetEntity: WorkflowTask::class, mappedBy: 'instance', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $tasks;

    /** @var Collection<int, WorkflowHistory> */
    #[ORM\OneToMany(targetEntity: WorkflowHistory::class, mappedBy: 'instance', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['occurredAt' => 'DESC'])]
    private Collection $history;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(DossierCase $case, Workflow $workflow, WorkflowStep $initialStep)
    {
        $this->case = $case;
        $this->workflow = $workflow;
        $this->currentStep = $initialStep;
        $this->startedAt = $this->stepEnteredAt = $this->updatedAt = new \DateTimeImmutable();
        $this->tasks = new ArrayCollection();
        $this->history = new ArrayCollection();

        if ($initialStep->getStandardDurationHours() !== null) {
            $this->stepDeadline = $this->stepEnteredAt->modify(sprintf('+%d hours', $initialStep->getStandardDurationHours()));
        }

        $case->setWorkflowInstance($this);
    }

    public function getId(): ?int { return $this->id; }

    public function getCase(): DossierCase { return $this->case; }
    public function setCase(DossierCase $case): void { $this->case = $case; }

    public function getWorkflow(): Workflow { return $this->workflow; }
    public function setWorkflow(Workflow $workflow): void { $this->workflow = $workflow; }

    public function getCurrentStep(): WorkflowStep { return $this->currentStep; }
    public function setCurrentStep(WorkflowStep $step): void
    {
        $this->currentStep = $step;
        $this->stepEnteredAt = new \DateTimeImmutable();
        if ($step->getStandardDurationHours() !== null) {
            $this->stepDeadline = $this->stepEnteredAt->modify(sprintf('+%d hours', $step->getStandardDurationHours()));
        } else {
            $this->stepDeadline = null;
        }
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getStatus(): WorkflowInstanceStatus { return $this->status; }
    public function setStatus(WorkflowInstanceStatus $status): void { $this->status = $status; $this->updatedAt = new \DateTimeImmutable(); }

    public function isCompleted(): bool { return $this->status === WorkflowInstanceStatus::Completed; }
    public function isRejected(): bool { return $this->status === WorkflowInstanceStatus::Rejected; }
    public function isOverdue(): bool
    {
        if ($this->stepDeadline === null || $this->isCompleted()) {
            return false;
        }
        return $this->stepDeadline < new \DateTimeImmutable();
    }

    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getStepEnteredAt(): \DateTimeImmutable { return $this->stepEnteredAt; }
    public function getStepDeadline(): ?\DateTimeImmutable { return $this->stepDeadline; }
    public function setStepDeadline(?\DateTimeImmutable $deadline): void { $this->stepDeadline = $deadline; }

    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function markCompleted(): void
    {
        $this->status = WorkflowInstanceStatus::Completed;
        $this->completedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @return Collection<int, WorkflowTask> */
    public function getTasks(): Collection { return $this->tasks; }
    public function addTask(WorkflowTask $task): void
    {
        if (!$this->tasks->contains($task)) {
            $this->tasks->add($task);
            $task->setInstance($this);
        }
    }

    public function getActiveTask(): ?WorkflowTask
    {
        foreach ($this->tasks as $task) {
            if ($task->isActive()) {
                return $task;
            }
        }
        return null;
    }

    /** @return Collection<int, WorkflowHistory> */
    public function getHistory(): Collection { return $this->history; }
    /** @return Collection<int, WorkflowHistory> */
    public function getHistories(): Collection { return $this->history; }
    public function addHistory(WorkflowHistory $log): void
    {
        if (!$this->history->contains($log)) {
            $this->history->add($log);
            $log->setInstance($this);
        }
    }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
