<?php

namespace App\Entity;

use App\Enum\CaseEventType;
use App\Repository\CaseEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CaseEventRepository::class)]
#[ORM\Table(name: 'case_events')]
#[ORM\Index(columns: ['case_id'], name: 'idx_case_events_case')]
#[ORM\Index(columns: ['occurred_at'], name: 'idx_case_events_date')]
#[ORM\Index(columns: ['action'], name: 'idx_case_events_action')]
#[ORM\Index(columns: ['actor_id'], name: 'idx_case_events_actor')]
#[ORM\HasLifecycleCallbacks]
class CaseEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DossierCase::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DossierCase $case;

    #[ORM\Column(enumType: CaseEventType::class)]
    private CaseEventType $eventType;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $action = null;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?OrganizationUnit $organizationUnit = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $oldValue = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $newValue = null;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    public function __construct(
        DossierCase $case,
        CaseEventType $eventType,
        string $label,
        ?User $actor = null,
        ?string $description = null,
        ?string $action = null,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?OrganizationUnit $organizationUnit = null,
        ?array $metadata = null
    ) {
        $this->case = $case;
        $this->eventType = $eventType;
        $this->label = $label;
        $this->actor = $actor;
        $this->description = $description;
        $this->action = $action ?? $eventType->value;
        $this->oldValue = $oldValue;
        $this->newValue = $newValue;
        $this->organizationUnit = $organizationUnit ?? $actor?->getOrganizationUnit();
        $this->metadata = $metadata;
        $this->occurredAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function preventUpdate(): void
    {
        throw new \LogicException('Les événements de traçabilité (CaseEvent) sont strictement immuables et ne peuvent pas être modifiés.');
    }

    #[ORM\PreRemove]
    public function preventRemove(): void
    {
        throw new \LogicException('Les événements de traçabilité (CaseEvent) sont strictement immuables et ne peuvent pas être supprimés.');
    }

    public function getId(): ?int { return $this->id; }

    public function getCase(): DossierCase { return $this->case; }
    public function setCase(DossierCase $case): void { $this->case = $case; }

    public function getEventType(): CaseEventType { return $this->eventType; }
    public function setEventType(CaseEventType $eventType): void { $this->eventType = $eventType; }

    public function getAction(): string
    {
        return $this->action ?? $this->getActionCode();
    }

    public function setAction(?string $action): self
    {
        $this->action = $action;
        return $this;
    }

    public function getActionCode(): string
    {
        if ($this->action !== null && $this->action !== '') {
            return $this->action;
        }
        if ($this->metadata !== null && isset($this->metadata['action'])) {
            return (string) $this->metadata['action'];
        }
        return $this->eventType->value;
    }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): void { $this->label = $label; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): void { $this->description = $description; }

    // Alias commentaire (Module 9 - Section 2)
    public function getComment(): ?string { return $this->description; }
    public function setComment(?string $comment): self
    {
        $this->description = $comment;
        return $this;
    }

    public function getActor(): ?User { return $this->actor; }
    public function setActor(?User $actor): void { $this->actor = $actor; }

    // Alias utilisateur (Module 9 - Section 2)
    public function getUser(): ?User { return $this->actor; }
    public function setUser(?User $user): self
    {
        $this->actor = $user;
        return $this;
    }

    public function getOrganizationUnit(): ?OrganizationUnit { return $this->organizationUnit; }
    public function setOrganizationUnit(?OrganizationUnit $unit): void { $this->organizationUnit = $unit; }

    // Alias structure (Module 9 - Section 2)
    public function getStructure(): ?OrganizationUnit { return $this->organizationUnit; }
    public function setStructure(?OrganizationUnit $unit): self
    {
        $this->organizationUnit = $unit;
        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array { return $this->metadata; }
    /** @param array<string, mixed>|null $metadata */
    public function setMetadata(?array $metadata): void { $this->metadata = $metadata; }

    public function getOldValue(): ?string { return $this->oldValue; }
    public function setOldValue(?string $oldValue): void { $this->oldValue = $oldValue; }

    public function getNewValue(): ?string { return $this->newValue; }
    public function setNewValue(?string $newValue): void { $this->newValue = $newValue; }

    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function setOccurredAt(\DateTimeImmutable $occurredAt): self
    {
        $this->occurredAt = $occurredAt;
        return $this;
    }

    // Alias date (Module 9 - Section 2)
    public function getDate(): \DateTimeImmutable { return $this->occurredAt; }
    public function setDate(\DateTimeImmutable $date): self
    {
        $this->occurredAt = $date;
        return $this;
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->occurredAt; }

    /**
     * Catégorie fonctionnelle pour les filtres de la timeline
     */
    public function getCategory(): string
    {
        $action = strtoupper($this->getAction());
        return match ($action) {
            'SUBMIT', 'SUBMITTED', 'ORIENT', 'ORIENTED', 'FORWARD', 'FORWARDED',
            'PROCESS', 'PROCESSED', 'RETURN', 'RETURNED', 'REJECT', 'REJECTED',
            'RELEASE', 'RELEASED', 'CASE_FORWARDED', 'CASE_UPDATED', 'CASE_RECEIVED',
            'CASE_IN_PROGRESS', 'CASE_PENDING', 'NOTE_ADDED', 'ANNOTATE', 'ANNOTATED' => 'workflow',
            'DOCUMENT_ADDED', 'DOCUMENT_DOWNLOADED' => 'documents',
            'ASSIGN', 'ASSIGNED', 'TAKE_IN_CHARGE', 'TAKEN_IN_CHARGE', 'CASE_ASSIGNED', 'DOSSIER_AFFECTE' => 'affectations',
            'VALIDATE', 'VALIDATED', 'CLOSE', 'COMPLETED', 'CASE_VALIDATED', 'CASE_CLOSED' => 'validations',
            default => 'workflow',
        };
    }
}
