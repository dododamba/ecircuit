<?php

namespace App\Entity;

use App\Enum\StructureType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'organization_units')]
class OrganizationUnit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60, unique: true)]
    private string $code;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\Column(enumType: StructureType::class)]
    private StructureType $type;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['displayOrder' => 'ASC', 'name' => 'ASC'])]
    private Collection $children;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(name: 'display_order', options: ['default' => 0])]
    private int $displayOrder = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $name, StructureType $type)
    {
        $this->code = $code;
        $this->name = $name;
        $this->type = $type;
        $this->children = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): void { $this->code = strtoupper(trim($code)); $this->updatedAt = new \DateTimeImmutable(); }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = trim($name); $this->updatedAt = new \DateTimeImmutable(); }
    public function getType(): StructureType { return $this->type; }
    public function setType(StructureType $type): void { $this->type = $type; $this->updatedAt = new \DateTimeImmutable(); }
    public function getParent(): ?self { return $this->parent; }
    /** @return Collection<int, self> */
    public function getChildren(): Collection { return $this->children; }
    public function setParent(?self $parent): void { $this->parent = $parent; $this->updatedAt = new \DateTimeImmutable(); }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): void { $this->description = $description ? trim($description) : null; $this->updatedAt = new \DateTimeImmutable(); }
    public function getDisplayOrder(): int { return $this->displayOrder; }
    public function setDisplayOrder(int $displayOrder): void { $this->displayOrder = $displayOrder; $this->updatedAt = new \DateTimeImmutable(); }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): void { $this->active = $active; $this->updatedAt = new \DateTimeImmutable(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function isWithin(self $scope): bool
    {
        $visited = [];
        for ($node = $this; $node !== null;) {
            try {
                if ($node === $scope || ($node->getId() !== null && $node->getId() === $scope->getId())) {
                    return true;
                }
                $id = $node->getId();
                if ($id !== null && isset($visited[$id])) {
                    break;
                }
                if ($id !== null) {
                    $visited[$id] = true;
                }
                $node = $node->parent;
            } catch (\Doctrine\ORM\EntityNotFoundException) {
                break;
            }
        }
        return false;
    }
    /** @return list<self> */
    public function getAllDescendants(): array
    {
        $descendants = [];
        foreach ($this->children as $child) {
            $descendants[] = $child;
            foreach ($child->getAllDescendants() as $subChild) {
                $descendants[] = $subChild;
            }
        }
        return $descendants;
    }
    public function getHierarchyPath(): string
    {
        $parts = [];
        $visited = [];
        for ($node = $this; $node !== null;) {
            try {
                $id = $node->getId();
                if ($id !== null && isset($visited[$id])) {
                    break;
                }
                if ($id !== null) {
                    $visited[$id] = true;
                }
                array_unshift($parts, $node->name);
                $node = $node->parent;
            } catch (\Doctrine\ORM\EntityNotFoundException) {
                break;
            }
        }
        return implode(' > ', $parts);
    }
}
