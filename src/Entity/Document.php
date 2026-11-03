<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'documents')]
class Document
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DossierCase::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DossierCase $case = null;

    #[ORM\ManyToOne(targetEntity: Correspondence::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Correspondence $correspondence = null;

    #[ORM\Column(length: 100)]
    private string $type; // DOCUMENT_INITIAL, PIECE_JOINTE, NOTE, REPONSE, DECISION, DOCUMENT_GENERE

    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 255)]
    private string $storageName;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column]
    private int $size;

    #[ORM\Column(length: 255)]
    private string $path;

    #[ORM\Column(options: ['default' => 1])]
    private int $version = 1;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $uploadedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $originalName,
        string $storageName,
        string $mimeType,
        int $size,
        string $path,
        string $type = 'DOCUMENT_INITIAL',
        ?User $uploadedBy = null
    ) {
        $this->originalName = $originalName;
        $this->storageName = $storageName;
        $this->mimeType = $mimeType;
        $this->size = $size;
        $this->path = $path;
        $this->type = $type;
        $this->uploadedBy = $uploadedBy;
        $this->uploadedAt = $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getCase(): ?DossierCase { return $this->case; }
    public function setCase(?DossierCase $case): void { $this->case = $case; }

    public function getCorrespondence(): ?Correspondence { return $this->correspondence; }
    public function setCorrespondence(?Correspondence $correspondence): void { $this->correspondence = $correspondence; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): void { $this->type = strtoupper(trim($type)); }

    public function getOriginalName(): string { return $this->originalName; }
    public function setOriginalName(string $originalName): void { $this->originalName = $originalName; }

    public function getStorageName(): string { return $this->storageName; }
    public function setStorageName(string $storageName): void { $this->storageName = $storageName; }

    public function getMimeType(): string { return $this->mimeType; }
    public function setMimeType(string $mimeType): void { $this->mimeType = $mimeType; }

    public function getSize(): int { return $this->size; }
    public function setSize(int $size): void { $this->size = $size; }

    public function getPath(): string { return $this->path; }
    public function setPath(string $path): void { $this->path = $path; }

    public function getVersion(): int { return $this->version; }
    public function setVersion(int $version): void { $this->version = $version; }

    public function getUploadedBy(): ?User { return $this->uploadedBy; }
    public function setUploadedBy(?User $uploadedBy): void { $this->uploadedBy = $uploadedBy; }

    public function getUploadedAt(): \DateTimeImmutable { return $this->uploadedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
