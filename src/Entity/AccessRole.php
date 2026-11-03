<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'access_roles')]
class AccessRole
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $code;

    #[ORM\Column(length: 160)]
    private string $label;

    #[ORM\Column(nullable: true, type: 'text')]
    private ?string $description = null;

    /** @var Collection<int, AccessPermission> */
    #[ORM\ManyToMany(targetEntity: AccessPermission::class, inversedBy: 'roles')]
    #[ORM\JoinTable(name: 'access_role_permissions')]
    private Collection $permissions;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'accessRoles')]
    private Collection $users;

    public function __construct(string $code, string $label, ?string $description = null)
    {
        $this->code = $code;
        $this->label = $label;
        $this->description = $description;
        $this->permissions = new ArrayCollection();
        $this->users = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): void { $this->code = strtoupper(trim($code)); }
    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): void { $this->label = trim($label); }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): void { $this->description = $description ? trim($description) : null; }
    /** @return Collection<int, AccessPermission> */
    public function getPermissions(): Collection { return $this->permissions; }
    public function grant(AccessPermission $permission): void
    {
        if (!$this->permissions->contains($permission)) { $this->permissions->add($permission); }
    }
    public function revoke(AccessPermission $permission): void
    {
        $this->permissions->removeElement($permission);
    }
    public function clearPermissions(): void
    {
        $this->permissions->clear();
    }
    public function hasPermission(string $permissionCode): bool
    {
        foreach ($this->permissions as $permission) {
            if ($permission->isActive() && $permission->getCode() === $permissionCode) {
                return true;
            }
        }
        return false;
    }
    /** @return Collection<int, User> */
    public function getUsers(): Collection { return $this->users; }
}
