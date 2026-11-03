<?php

namespace App\Entity;

use App\Enum\EntryChannel;
use App\Repository\DossierCaseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * DossierCase — the business container for a workflow case.
 * Named DossierCase to avoid collision with PHP reserved word "Case".
 * Automatically created when an incoming Correspondence is registered.
 */
#[ORM\Entity(repositoryClass: DossierCaseRepository::class)]
#[ORM\Table(name: 'cases')]
#[ORM\Index(columns: ['reference'], name: 'idx_case_reference')]
#[ORM\Index(columns: ['status_code'], name: 'idx_case_status')]
#[ORM\Index(columns: ['priority_code'], name: 'idx_case_priority')]
#[ORM\Index(columns: ['current_structure_id'], name: 'idx_case_current_structure')]
#[ORM\Index(columns: ['current_assignee_id'], name: 'idx_case_current_assignee')]
class DossierCase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Same reference as the originating Correspondence (MCI-YYYY-NNNNNN). */
    #[ORM\Column(length: 20, unique: true)]
    private string $reference;

    #[ORM\Column(length: 500)]
    private string $subject;

    /** Status code from ReferenceCategory::DossierStatus. Stored as plain string for flexibility. */
    #[ORM\Column(length: 40, name: 'status_code')]
    private string $statusCode = 'RECEIVED';

    /** Priority code from ReferenceCategory::Priority. */
    #[ORM\Column(length: 40, name: 'priority_code', nullable: true)]
    private ?string $priorityCode = null;

    /** Entry channel — immutable after creation. */
    #[ORM\Column(length: 30, enumType: EntryChannel::class)]
    private EntryChannel $entryChannel;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Sender $sender = null;

    /** Type of request/dossier (ReferenceCategory::RequestType or DocumentType). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ReferenceItem $caseType = null;

    /** The structure currently holding the case — used by future workflow engine. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'current_structure_id', nullable: true, onDelete: 'SET NULL')]
    private ?OrganizationUnit $currentStructure = null;

    /** The user currently responsible — used by future workflow engine. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'current_assignee_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $currentAssignee = null;

    /** The administrative circuit assigned to this case — prepared for Module 05. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AdministrativeCircuit $circuit = null;

    #[ORM\OneToOne(targetEntity: WorkflowInstance::class, mappedBy: 'case', cascade: ['persist', 'remove'])]
    private ?WorkflowInstance $workflowInstance = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deadline = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $closedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $archivedBy = null;

    /** @var Collection<int, Correspondence> */
    #[ORM\OneToMany(targetEntity: Correspondence::class, mappedBy: 'case')]
    #[ORM\OrderBy(['receivedAt' => 'ASC'])]
    private Collection $correspondences;

    /** @var Collection<int, Document> */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'case', cascade: ['persist'])]
    #[ORM\OrderBy(['uploadedAt' => 'DESC'])]
    private Collection $documents;

    /** @var Collection<int, CaseEvent> */
    #[ORM\OneToMany(targetEntity: CaseEvent::class, mappedBy: 'case', cascade: ['persist'])]
    #[ORM\OrderBy(['occurredAt' => 'ASC'])]
    private Collection $events;

    /** @var Collection<int, CaseTransmission> */
    #[ORM\OneToMany(targetEntity: CaseTransmission::class, mappedBy: 'case', cascade: ['persist'])]
    #[ORM\OrderBy(['transmittedAt' => 'ASC'])]
    private Collection $transmissions;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $reference, string $subject, EntryChannel $entryChannel, \DateTimeImmutable $receivedAt)
    {
        $this->reference = $reference;
        $this->subject = trim($subject);
        $this->entryChannel = $entryChannel;
        $this->receivedAt = $receivedAt;
        $this->correspondences = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->events = new ArrayCollection();
        $this->transmissions = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getReference(): string { return $this->reference; }
    public function getSubject(): string { return $this->subject; }
    public function setSubject(string $subject): void { $this->subject = trim($subject); $this->updatedAt = new \DateTimeImmutable(); }

    public function getStatusCode(): string { return $this->statusCode; }
    public function setStatusCode(string $statusCode): void { $this->statusCode = strtoupper(trim($statusCode)); $this->updatedAt = new \DateTimeImmutable(); }

    public function getPriorityCode(): ?string { return $this->priorityCode; }
    public function setPriorityCode(?string $priorityCode): void { $this->priorityCode = $priorityCode ? strtoupper(trim($priorityCode)) : null; $this->updatedAt = new \DateTimeImmutable(); }

    public function getEntryChannel(): EntryChannel { return $this->entryChannel; }
    // Entry channel is immutable — no setter.

    public function getSender(): ?Sender { return $this->sender; }
    public function setSender(?Sender $sender): void { $this->sender = $sender; $this->updatedAt = new \DateTimeImmutable(); }

    public function getCaseType(): ?ReferenceItem { return $this->caseType; }
    public function setCaseType(?ReferenceItem $caseType): void { $this->caseType = $caseType; $this->updatedAt = new \DateTimeImmutable(); }

    public function getCurrentStructure(): ?OrganizationUnit { return $this->currentStructure; }
    public function setCurrentStructure(?OrganizationUnit $structure): void { $this->currentStructure = $structure; $this->updatedAt = new \DateTimeImmutable(); }

    public function getCurrentAssignee(): ?User { return $this->currentAssignee; }
    public function setCurrentAssignee(?User $user): void { $this->currentAssignee = $user; $this->updatedAt = new \DateTimeImmutable(); }

    public function getCircuit(): ?AdministrativeCircuit { return $this->circuit; }
    public function setCircuit(?AdministrativeCircuit $circuit): void { $this->circuit = $circuit; $this->updatedAt = new \DateTimeImmutable(); }

    public function getWorkflowInstance(): ?WorkflowInstance { return $this->workflowInstance; }
    public function setWorkflowInstance(?WorkflowInstance $workflowInstance): void { $this->workflowInstance = $workflowInstance; }

    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
    public function getReceptionDate(): \DateTimeImmutable { return $this->receivedAt; }
    public function getDeadline(): ?\DateTimeImmutable { return $this->deadline; }
    public function setDeadline(?\DateTimeImmutable $deadline): void { $this->deadline = $deadline; $this->updatedAt = new \DateTimeImmutable(); }

    public function isOverdue(): bool
    {
        if ($this->deadline === null || $this->isClosed() || $this->isArchived()) {
            return false;
        }
        return $this->deadline < new \DateTimeImmutable();
    }

    public function isClosed(): bool { return $this->closedAt !== null; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function getClosedBy(): ?User { return $this->closedBy; }
    public function close(User $user): void
    {
        if ($this->closedAt !== null) { return; }
        $this->closedAt = new \DateTimeImmutable();
        $this->closedBy = $user;
        $this->statusCode = 'COMPLETED';
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isArchived(): bool { return $this->archivedAt !== null; }
    public function getArchivedAt(): ?\DateTimeImmutable { return $this->archivedAt; }
    public function getArchivedBy(): ?User { return $this->archivedBy; }
    public function archive(User $user): void
    {
        if ($this->archivedAt !== null) { return; }
        $this->archivedAt = new \DateTimeImmutable();
        $this->archivedBy = $user;
        $this->statusCode = 'ARCHIVED';
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @return Collection<int, Correspondence> */
    public function getCorrespondences(): Collection { return $this->correspondences; }
    public function addCorrespondence(Correspondence $correspondence): void
    {
        if (!$this->correspondences->contains($correspondence)) {
            $this->correspondences->add($correspondence);
            $correspondence->setCase($this);
        }
    }

    /** @return Collection<int, Document> */
    public function getDocuments(): Collection { return $this->documents; }
    public function addDocument(Document $document): void
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->setCase($this);
        }
    }

    /** @return Collection<int, CaseEvent> */
    public function getEvents(): Collection { return $this->events; }
    public function addEvent(CaseEvent $event): void
    {
        if (!$this->events->contains($event)) {
            $this->events->add($event);
        }
    }

    /** @return Collection<int, CaseTransmission> */
    public function getTransmissions(): Collection { return $this->transmissions; }
    public function addTransmission(CaseTransmission $transmission): void
    {
        if (!$this->transmissions->contains($transmission)) {
            $this->transmissions->add($transmission);
        }
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
