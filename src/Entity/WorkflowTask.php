<?php

namespace App\Entity;

use App\Enum\WorkflowTaskStatus;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'workflow_tasks')]
#[ORM\Index(columns: ['instance_id'], name: 'idx_task_instance')]
#[ORM\Index(columns: ['step_id'], name: 'idx_task_step')]
#[ORM\Index(columns: ['target_structure_id'], name: 'idx_task_structure')]
#[ORM\Index(columns: ['target_user_id'], name: 'idx_task_user')]
#[ORM\Index(columns: ['status'], name: 'idx_task_status')]
class WorkflowTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WorkflowInstance $instance;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WorkflowStep $step;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'target_structure_id', nullable: true, onDelete: 'SET NULL')]
    private ?OrganizationUnit $targetStructure = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'target_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $targetUser = null;

    #[ORM\Column(length: 40, enumType: WorkflowTaskStatus::class)]
    private WorkflowTaskStatus $status = WorkflowTaskStatus::Pending;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $instructions = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deadline = null;

    #[ORM\Column(name: 'escalated_at', nullable: true)]
    private ?\DateTimeImmutable $escalatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $completedBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        WorkflowInstance $instance,
        WorkflowStep $step,
        ?OrganizationUnit $targetStructure = null,
        ?User $targetUser = null,
        ?string $instructions = null,
        ?int $durationHours = null
    ) {
        $this->instance = $instance;
        $this->step = $step;
        $this->targetStructure = $targetStructure ?: $step->getTargetStructure();
        $this->targetUser = $targetUser;
        $this->instructions = $instructions;
        $this->createdAt = new \DateTimeImmutable();

        $hours = $durationHours ?? $step->getStandardDurationHours();
        if ($hours !== null) {
            $this->deadline = $this->createdAt->modify(sprintf('+%d hours', $hours));
        }

        $instance->addTask($this);
    }

    public function getId(): ?int { return $this->id; }

    public function getInstance(): WorkflowInstance { return $this->instance; }
    public function setInstance(WorkflowInstance $instance): void { $this->instance = $instance; }

    public function getStep(): WorkflowStep { return $this->step; }
    public function setStep(WorkflowStep $step): void { $this->step = $step; }

    public function getTargetStructure(): ?OrganizationUnit { return $this->targetStructure; }
    public function setTargetStructure(?OrganizationUnit $structure): void { $this->targetStructure = $structure; }

    public function getTargetUser(): ?User { return $this->targetUser; }
    public function setTargetUser(?User $user): void { $this->targetUser = $user; }

    public function getStatus(): WorkflowTaskStatus { return $this->status; }
    public function setStatus(WorkflowTaskStatus $status): void { $this->status = $status; }

    public function isActive(): bool
    {
        return $this->status === WorkflowTaskStatus::Pending || $this->status === WorkflowTaskStatus::InProgress;
    }

    public function isOverdue(): bool
    {
        if ($this->deadline === null || !$this->isActive()) {
            return false;
        }
        return $this->deadline < new \DateTimeImmutable();
    }

    public function getInstructions(): ?string { return $this->instructions; }
    public function setInstructions(?string $instructions): void { $this->instructions = $instructions; }

    public function getDeadline(): ?\DateTimeImmutable { return $this->deadline; }
    public function setDeadline(?\DateTimeImmutable $deadline): void { $this->deadline = $deadline; }
    public function getDueAt(): ?\DateTimeImmutable { return $this->deadline; }
    public function setDueAt(?\DateTimeImmutable $dueAt): void { $this->deadline = $dueAt; }

    public function getEscalatedAt(): ?\DateTimeImmutable { return $this->escalatedAt; }
    public function isEscalated(): bool { return $this->escalatedAt !== null; }
    public function markEscalated(?\DateTimeImmutable $at = null): void { $this->escalatedAt = $at ?? new \DateTimeImmutable(); }

    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function start(): void
    {
        $this->status = WorkflowTaskStatus::InProgress;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function getCompletedBy(): ?User { return $this->completedBy; }
    public function getComment(): ?string { return $this->comment; }

    public function complete(User $user, ?string $comment = null): void
    {
        $this->status = WorkflowTaskStatus::Completed;
        $this->completedAt = new \DateTimeImmutable();
        $this->completedBy = $user;
        $this->comment = $comment;
    }

    public function cancel(?string $reason = null): void
    {
        $this->status = WorkflowTaskStatus::Cancelled;
        $this->comment = $reason;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
