<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'access_permissions')]
class AccessPermission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $code;

    #[ORM\Column(length: 160)]
    private string $label;

    #[ORM\Column(length: 80)]
    private string $module;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** @var Collection<int, AccessRole> */
    #[ORM\ManyToMany(targetEntity: AccessRole::class, mappedBy: 'permissions')]
    private Collection $roles;

    public function __construct(string $code, string $label, string $module)
    {
        $this->code = $code;
        $this->label = $label;
        $this->module = $module;
        $this->roles = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): void { $this->code = strtoupper(trim($code)); }
    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): void { $this->label = trim($label); }
    public function getModule(): string { return $this->module; }
    public function setModule(string $module): void { $this->module = trim($module); }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): void { $this->active = $active; }
}
