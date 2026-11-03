<?php

namespace App\Entity;

use App\Enum\WorkflowAction;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'workflow_histories')]
#[ORM\Index(columns: ['instance_id'], name: 'idx_history_instance')]
#[ORM\Index(columns: ['action'], name: 'idx_history_action')]
#[ORM\Index(columns: ['occurred_at'], name: 'idx_history_occurred')]
class WorkflowHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'history')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WorkflowInstance $instance;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?WorkflowStep $sourceStep = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WorkflowStep $targetStep;

    #[ORM\Column(length: 40, enumType: WorkflowAction::class)]
    private WorkflowAction $action;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?OrganizationUnit $organizationUnit = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(nullable: true)]
    private ?int $durationSeconds = null;

    public function __construct(
        WorkflowInstance $instance,
        ?WorkflowStep $sourceStep,
        WorkflowStep $targetStep,
        WorkflowAction $action,
        ?User $actor = null,
        ?OrganizationUnit $organizationUnit = null,
        ?string $comment = null,
        ?array $metadata = null,
        ?int $durationSeconds = null
    ) {
        $this->instance = $instance;
        $this->sourceStep = $sourceStep;
        $this->targetStep = $targetStep;
        $this->action = $action;
        $this->actor = $actor;
        $this->organizationUnit = $organizationUnit ?: $actor?->getOrganizationUnit();
        $this->comment = $comment;
        $this->metadata = $metadata;
        $this->durationSeconds = $durationSeconds;
        $this->occurredAt = new \DateTimeImmutable();

        $instance->addHistory($this);
    }

    public function getId(): ?int { return $this->id; }

    public function getInstance(): WorkflowInstance { return $this->instance; }
    public function setInstance(WorkflowInstance $instance): void { $this->instance = $instance; }

    public function getSourceStep(): ?WorkflowStep { return $this->sourceStep; }
    public function getTargetStep(): WorkflowStep { return $this->targetStep; }

    public function getAction(): WorkflowAction { return $this->action; }
    public function getActor(): ?User { return $this->actor; }
    public function getOrganizationUnit(): ?OrganizationUnit { return $this->organizationUnit; }

    public function getComment(): ?string { return $this->comment; }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array { return $this->metadata; }

    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function getDurationSeconds(): ?int { return $this->durationSeconds; }
}
