<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'administrative_circuit_steps')]
#[ORM\UniqueConstraint(name: 'circuit_position_unique', columns: ['circuit_id', 'position'])]
class CircuitStep
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'steps')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AdministrativeCircuit $circuit;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private OrganizationUnit $organizationUnit;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(name: 'standard_duration_hours', nullable: true)]
    private ?int $standardDurationHours = 48;

    public function __construct(
        AdministrativeCircuit $circuit,
        OrganizationUnit $organizationUnit,
        int $position,
        ?string $label = null,
        ?int $standardDurationHours = 48
    ) {
        $this->circuit = $circuit;
        $this->organizationUnit = $organizationUnit;
        $this->position = $position;
        $this->label = $label;
        $this->standardDurationHours = $standardDurationHours;
        $circuit->addStep($this);
    }

    public function getId(): ?int { return $this->id; }
    public function getCircuit(): AdministrativeCircuit { return $this->circuit; }
    public function setCircuit(AdministrativeCircuit $circuit): void { $this->circuit = $circuit; }
    public function getOrganizationUnit(): OrganizationUnit { return $this->organizationUnit; }
    public function setOrganizationUnit(OrganizationUnit $organizationUnit): void { $this->organizationUnit = $organizationUnit; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): void { $this->position = $position; }
    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $label): void { $this->label = $label ? trim($label) : null; }
    public function getStandardDurationHours(): ?int { return $this->standardDurationHours; }
    public function setStandardDurationHours(?int $hours): void { $this->standardDurationHours = $hours; }
}
