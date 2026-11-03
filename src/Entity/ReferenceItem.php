<?php

namespace App\Entity;

use App\Enum\ReferenceCategory;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'reference_items')]
#[ORM\UniqueConstraint(name: 'reference_category_code_unique', columns: ['category', 'code'])]
class ReferenceItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    #[ORM\Column(enumType: ReferenceCategory::class)]
    private ReferenceCategory $category;
    #[ORM\Column(length: 100)]
    private string $code;
    #[ORM\Column(length: 180)]
    private string $label;
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $uiColor = null;
    #[ORM\Column(name: 'display_order', options: ['default' => 0])]
    private int $displayOrder = 0;
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function __construct(ReferenceCategory $category, string $code, string $label, ?string $description = null, int $displayOrder = 0)
    {
        $this->category = $category; $this->code = $code; $this->label = $label;
        $this->description = $description; $this->displayOrder = $displayOrder;
    }
    public function getId(): ?int { return $this->id; }
    public function getCategory(): ReferenceCategory { return $this->category; }
    public function setCategory(ReferenceCategory $category): void { $this->category = $category; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): void { $this->code = strtoupper(trim($code)); }
    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): void { $this->label = trim($label); }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): void { $this->description = $description ? trim($description) : null; }
    public function getUiColor(): ?string { return $this->uiColor; }
    public function setUiColor(?string $uiColor): void { $this->uiColor = $uiColor ? trim($uiColor) : null; }
    public function getDisplayOrder(): int { return $this->displayOrder; }
    public function setDisplayOrder(int $displayOrder): void { $this->displayOrder = $displayOrder; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): void { $this->active = $active; }
}
