<?php

namespace App\Entity;

use App\Enum\WorkflowAction;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'workflow_transitions')]
#[ORM\Index(columns: ['workflow_id'], name: 'idx_trans_workflow')]
#[ORM\Index(columns: ['source_step_id'], name: 'idx_trans_source')]
#[ORM\Index(columns: ['target_step_id'], name: 'idx_trans_target')]
class WorkflowTransition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'transitions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workflow $workflow;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'source_step_id', nullable: false, onDelete: 'CASCADE')]
    private WorkflowStep $sourceStep;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'target_step_id', nullable: false, onDelete: 'CASCADE')]
    private WorkflowStep $targetStep;

    #[ORM\Column(length: 40, enumType: WorkflowAction::class)]
    private WorkflowAction $action;

    #[ORM\Column(length: 180)]
    private string $label;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AccessRole $authorizedRole = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $requiredPermission = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $commentRequired = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $isReturn = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function __construct(
        Workflow $workflow,
        WorkflowStep $sourceStep,
        WorkflowStep $targetStep,
        WorkflowAction $action,
        ?string $label = null,
        ?AccessRole $authorizedRole = null,
        ?string $requiredPermission = null,
        bool $commentRequired = false,
        bool $isReturn = false
    ) {
        $this->workflow = $workflow;
        $this->sourceStep = $sourceStep;
        $this->targetStep = $targetStep;
        $this->action = $action;
        $this->label = $label ?: $action->label();
        $this->authorizedRole = $authorizedRole;
        $this->requiredPermission = $requiredPermission ? strtoupper(trim($requiredPermission)) : null;
        $this->commentRequired = $commentRequired;
        $this->isReturn = $isReturn;
        $workflow->addTransition($this);
    }

    public function getId(): ?int { return $this->id; }

    public function getWorkflow(): Workflow { return $this->workflow; }
    public function setWorkflow(Workflow $workflow): void { $this->workflow = $workflow; }

    public function getSourceStep(): WorkflowStep { return $this->sourceStep; }
    public function setSourceStep(WorkflowStep $sourceStep): void { $this->sourceStep = $sourceStep; }

    public function getTargetStep(): WorkflowStep { return $this->targetStep; }
    public function setTargetStep(WorkflowStep $targetStep): void { $this->targetStep = $targetStep; }

    public function getAction(): WorkflowAction { return $this->action; }
    public function setAction(WorkflowAction $action): void { $this->action = $action; }

    public function getLabel(): string { return $this->label; }
    public function getName(): string { return $this->label; }
    public function setLabel(string $label): void { $this->label = trim($label); }

    public function getAuthorizedRole(): ?AccessRole { return $this->authorizedRole; }
    public function setAuthorizedRole(?AccessRole $authorizedRole): void { $this->authorizedRole = $authorizedRole; }

    public function getRequiredPermission(): ?string { return $this->requiredPermission; }
    public function setRequiredPermission(?string $permission): void { $this->requiredPermission = $permission ? strtoupper(trim($permission)) : null; }

    public function isCommentRequired(): bool { return $this->commentRequired; }
    public function setCommentRequired(bool $required): void { $this->commentRequired = $required; }

    public function isReturn(): bool { return $this->isReturn; }
    public function setIsReturn(bool $isReturn): void { $this->isReturn = $isReturn; }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): void { $this->active = $active; }
}
