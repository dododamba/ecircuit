<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'administrative_circuits')]
class AdministrativeCircuit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 100, unique: true)]
    private string $code;
    #[ORM\Column(length: 180)]
    private string $label;
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;
    /** @var Collection<int, CircuitStep> */
    #[ORM\OneToMany(targetEntity: CircuitStep::class, mappedBy: 'circuit', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $steps;
    public function __construct(string $code, string $label, ?string $description = null) { $this->code = $code; $this->label = $label; $this->description = $description; $this->steps = new ArrayCollection(); }
    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): void { $this->code = strtoupper(trim($code)); }
    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): void { $this->label = trim($label); }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): void { $this->description = $description ? trim($description) : null; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): void { $this->active = $active; }
    /** @return Collection<int, CircuitStep> */
    public function getSteps(): Collection { return $this->steps; }
    public function addStep(CircuitStep $step): void { if (!$this->steps->contains($step)) { $this->steps->add($step); } }
    public function removeStep(CircuitStep $step): void { $this->steps->removeElement($step); }
    public function clearSteps(): void { $this->steps->clear(); }
}
