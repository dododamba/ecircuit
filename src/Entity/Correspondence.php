<?php

namespace App\Entity;

use App\Enum\CorrespondenceStatus;
use App\Enum\EntryChannel;
use App\Repository\CorrespondenceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CorrespondenceRepository::class)]
#[ORM\Table(name: 'correspondences')]
#[ORM\Index(columns: ['reference'], name: 'idx_correspondence_reference')]
#[ORM\Index(columns: ['received_at'], name: 'idx_correspondence_received_at')]
#[ORM\Index(columns: ['status'], name: 'idx_correspondence_status')]
#[ORM\Index(columns: ['entry_channel'], name: 'idx_correspondence_channel')]
class Correspondence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Unique business reference: MCI-YYYY-NNNNNN or MCI-OUT-YYYY-NNNNNN. Non-modifiable after assignment. */
    #[ORM\Column(length: 25, unique: true, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(length: 500)]
    private string $subject;

    /** Channel of entry — immutable after registration. */
    #[ORM\Column(length: 30, enumType: EntryChannel::class)]
    private EntryChannel $entryChannel;

    #[ORM\Column(enumType: CorrespondenceStatus::class)]
    private CorrespondenceStatus $status = CorrespondenceStatus::Draft;

    /** Type of document (ReferenceCategory::DocumentType). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ReferenceItem $documentType = null;

    /** Type of correspondence (ReferenceCategory::MailType). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ReferenceItem $mailType = null;

    /** Priority (ReferenceCategory::Priority). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ReferenceItem $priority = null;

    #[ORM\ManyToOne(cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Sender $sender = null;

    /** Outgoing: recipient name */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $recipientName = null;

    /** Outgoing: recipient organisation */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $recipientOrganisation = null;

    /** Outgoing: dispatch method (PORTEUR, COURRIER_POSTAL, EMAIL_OFFICIEL, REMISE_MAINS_PROPRES) */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $dispatchMethod = null;

    /** Outgoing: issuing structure */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'issuing_structure_id', nullable: true, onDelete: 'SET NULL')]
    private ?OrganizationUnit $issuingStructure = null;

    /** Actual date the physical document was received (incoming) or drafted (outgoing). */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $receivedAt;

    /** Date/time the correspondence was registered in the system. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $registeredAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $registeredBy = null;

    /** Outgoing: validated by */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $validatedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observations = null;

    /** The case this correspondence belongs to. */
    #[ORM\ManyToOne(targetEntity: DossierCase::class, inversedBy: 'correspondences')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DossierCase $case = null;

    /** Primary document attached to the correspondence. */
    #[ORM\OneToOne(cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $mainDocument = null;

    /** @var Collection<int, Document> */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'correspondence', cascade: ['persist'])]
    private Collection $attachments;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $subject, EntryChannel $entryChannel, \DateTimeImmutable $receivedAt)
    {
        $this->subject = trim($subject);
        $this->entryChannel = $entryChannel;
        $this->receivedAt = $receivedAt;
        $this->attachments = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getReference(): ?string { return $this->reference; }
    /** Reference is set once and never changed. */
    public function assignReference(string $reference): void
    {
        if ($this->reference !== null) {
            throw new \LogicException('Reference already assigned and cannot be changed.');
        }
        $this->reference = $reference;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isOutgoing(): bool
    {
        if ($this->reference !== null && str_starts_with($this->reference, 'MCI-OUT-')) {
            return true;
        }
        if ($this->mailType !== null && $this->mailType->getCode() === 'COURRIER_SORTANT') {
            return true;
        }
        return $this->recipientName !== null;
    }

    public function getSubject(): string { return $this->subject; }
    public function setSubject(string $subject): void { $this->subject = trim($subject); $this->updatedAt = new \DateTimeImmutable(); }

    public function getEntryChannel(): EntryChannel { return $this->entryChannel; }
    public function getChannel(): EntryChannel { return $this->entryChannel; }

    public function getStatus(): CorrespondenceStatus { return $this->status; }
    public function setStatus(CorrespondenceStatus $status): void { $this->status = $status; $this->updatedAt = new \DateTimeImmutable(); }

    public function getDocumentType(): ?ReferenceItem { return $this->documentType; }
    public function setDocumentType(?ReferenceItem $documentType): void { $this->documentType = $documentType; $this->updatedAt = new \DateTimeImmutable(); }

    public function getMailType(): ?ReferenceItem { return $this->mailType; }
    public function setMailType(?ReferenceItem $mailType): void { $this->mailType = $mailType; $this->updatedAt = new \DateTimeImmutable(); }

    public function getPriority(): ?ReferenceItem { return $this->priority; }
    public function setPriority(?ReferenceItem $priority): void { $this->priority = $priority; $this->updatedAt = new \DateTimeImmutable(); }

    public function getSender(): ?Sender { return $this->sender; }
    public function setSender(?Sender $sender): void { $this->sender = $sender; $this->updatedAt = new \DateTimeImmutable(); }

    public function getRecipientName(): ?string { return $this->recipientName; }
    public function setRecipientName(?string $recipientName): void { $this->recipientName = $recipientName ? trim($recipientName) : null; $this->updatedAt = new \DateTimeImmutable(); }

    public function getRecipientOrganisation(): ?string { return $this->recipientOrganisation; }
    public function setRecipientOrganisation(?string $recipientOrganisation): void { $this->recipientOrganisation = $recipientOrganisation ? trim($recipientOrganisation) : null; $this->updatedAt = new \DateTimeImmutable(); }

    public function getDispatchMethod(): ?string { return $this->dispatchMethod; }
    public function setDispatchMethod(?string $dispatchMethod): void { $this->dispatchMethod = $dispatchMethod ? strtoupper(trim($dispatchMethod)) : null; $this->updatedAt = new \DateTimeImmutable(); }

    public function getIssuingStructure(): ?OrganizationUnit { return $this->issuingStructure; }
    public function setIssuingStructure(?OrganizationUnit $issuingStructure): void { $this->issuingStructure = $issuingStructure; $this->updatedAt = new \DateTimeImmutable(); }

    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
    public function setReceivedAt(\DateTimeImmutable $receivedAt): void { $this->receivedAt = $receivedAt; $this->updatedAt = new \DateTimeImmutable(); }

    /** Outgoing alias for drafting date */
    public function getDraftedAt(): \DateTimeImmutable { return $this->receivedAt; }

    public function getRegisteredAt(): ?\DateTimeImmutable { return $this->registeredAt; }
    public function getRegisteredBy(): ?User { return $this->registeredBy; }
    public function setRegisteredBy(?User $user): void { $this->registeredBy = $user; $this->updatedAt = new \DateTimeImmutable(); }
    public function setRegisteredAt(?\DateTimeImmutable $registeredAt): void { $this->registeredAt = $registeredAt; $this->updatedAt = new \DateTimeImmutable(); }

    public function markRegistered(User $user): void
    {
        $this->registeredAt = new \DateTimeImmutable();
        $this->registeredBy = $user;
        $this->status = CorrespondenceStatus::Validated;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getValidatedBy(): ?User { return $this->validatedBy; }
    public function getValidatedAt(): ?\DateTimeImmutable { return $this->validatedAt; }
    public function validate(User $user): void
    {
        $this->validatedBy = $user;
        $this->validatedAt = new \DateTimeImmutable();
        $this->status = CorrespondenceStatus::Validated;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getReleasedAt(): ?\DateTimeImmutable { return $this->releasedAt; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->releasedAt; }
    public function setReleasedAt(?\DateTimeImmutable $releasedAt): void { $this->releasedAt = $releasedAt; $this->updatedAt = new \DateTimeImmutable(); }

    public function release(): void
    {
        $this->releasedAt = new \DateTimeImmutable();
        $this->status = CorrespondenceStatus::Released;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markSent(?\DateTimeImmutable $sentAt = null): void
    {
        $this->releasedAt = $sentAt ?? new \DateTimeImmutable();
        $this->status = CorrespondenceStatus::Sent;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getObservations(): ?string { return $this->observations; }
    public function setObservations(?string $observations): void { $this->observations = $observations ? trim($observations) : null; $this->updatedAt = new \DateTimeImmutable(); }

    public function getCase(): ?DossierCase { return $this->case; }
    public function setCase(?DossierCase $case): void { $this->case = $case; $this->updatedAt = new \DateTimeImmutable(); }

    public function getMainDocument(): ?Document { return $this->mainDocument; }
    public function setMainDocument(?Document $document): void { $this->mainDocument = $document; $this->updatedAt = new \DateTimeImmutable(); }

    /** @return Collection<int, Document> */
    public function getAttachments(): Collection { return $this->attachments; }
    public function addAttachment(Document $document): void
    {
        if (!$this->attachments->contains($document)) {
            $this->attachments->add($document);
            $document->setCorrespondence($this);
        }
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
