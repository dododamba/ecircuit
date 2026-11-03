<?php

namespace App\Entity;

use App\Enum\AccountStatus;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[UniqueEntity(fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 180, unique: true)]
    #[Assert\Email]
    private string $email;
    #[ORM\Column(length: 100)]
    private string $firstName;
    #[ORM\Column(length: 100)]
    private string $lastName;
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;
    #[ORM\Column(length: 100, unique: true, nullable: true)]
    private ?string $username = null;
    #[ORM\Column]
    private string $password;
    #[ORM\Column(enumType: AccountStatus::class)]
    private AccountStatus $status = AccountStatus::Active;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?OrganizationUnit $organizationUnit = null;
    /** @var Collection<int, AccessRole> */
    #[ORM\ManyToMany(targetEntity: AccessRole::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'user_access_roles')]
    private Collection $accessRoles;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $disabledAt = null;

    public function __construct(string $email, string $firstName, string $lastName)
    {
        $this->email = strtolower($email);
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->accessRoles = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }
    public function getId(): ?int { return $this->id; }
    public function getUserIdentifier(): string { return $this->email; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = strtolower(trim($email)); $this->updatedAt = new \DateTimeImmutable(); }
    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): void { $this->firstName = trim($firstName); $this->updatedAt = new \DateTimeImmutable(); }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): void { $this->lastName = trim($lastName); $this->updatedAt = new \DateTimeImmutable(); }
    public function getFullName(): string { return trim($this->firstName.' '.$this->lastName); }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): void { $this->phone = $phone ? trim($phone) : null; $this->updatedAt = new \DateTimeImmutable(); }
    public function getUsername(): ?string { return $this->username; }
    public function setUsername(?string $username): void { $this->username = $username ? trim($username) : null; $this->updatedAt = new \DateTimeImmutable(); }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $hashedPassword): void { $this->password = $hashedPassword; $this->updatedAt = new \DateTimeImmutable(); }
    public function eraseCredentials(): void {}
    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        foreach ($this->accessRoles as $role) {
            $code = strtoupper(trim($role->getCode()));
            $cleanCode = str_starts_with($code, 'ROLE_') ? $code : 'ROLE_'.$code;
            $roles[] = $cleanCode;
            if ($cleanCode === 'ROLE_ADMINISTRATEUR') {
                $roles[] = 'ROLE_ADMIN';
            } elseif ($cleanCode === 'ROLE_ADMIN') {
                $roles[] = 'ROLE_ADMINISTRATEUR';
            }
            if ($cleanCode === 'ROLE_AGENT_CABINET') {
                $roles[] = 'ROLE_CABINET_AGENT';
            } elseif ($cleanCode === 'ROLE_CABINET_AGENT') {
                $roles[] = 'ROLE_AGENT_CABINET';
            }
        }
        return array_values(array_unique($roles));
    }
    /** @return Collection<int, AccessRole> */
    public function getAccessRoles(): Collection { return $this->accessRoles; }
    public function addAccessRole(AccessRole $role): void { if (!$this->accessRoles->contains($role)) { $this->accessRoles->add($role); } }
    public function removeAccessRole(AccessRole $role): void { $this->accessRoles->removeElement($role); }
    public function clearAccessRoles(): void { $this->accessRoles->clear(); }
    public function hasAccessRole(string $code): bool
    {
        $target = strtoupper(trim($code));
        $targetClean = str_starts_with($target, 'ROLE_') ? substr($target, 5) : $target;
        if ($targetClean === 'ADMINISTRATEUR') {
            $targetClean = 'ADMIN';
        }
        if ($targetClean === 'CABINET_AGENT') {
            $targetClean = 'AGENT_CABINET';
        }

        foreach ($this->accessRoles as $role) {
            $roleCode = strtoupper(trim($role->getCode()));
            $roleClean = str_starts_with($roleCode, 'ROLE_') ? substr($roleCode, 5) : $roleCode;
            if ($roleClean === 'ADMINISTRATEUR') {
                $roleClean = 'ADMIN';
            }
            if ($roleClean === 'CABINET_AGENT') {
                $roleClean = 'AGENT_CABINET';
            }

            if ($roleClean === $targetClean) {
                return true;
            }
        }
        return false;
    }
    public function hasPermission(string $permissionCode): bool
    {
        $code = strtoupper(trim($permissionCode));
        $aliases = [$code];
        if ($code === 'USER_EDIT') { $aliases[] = 'USER_UPDATE'; }
        if ($code === 'USER_UPDATE') { $aliases[] = 'USER_EDIT'; }
        if ($code === 'USER_DISABLE') { $aliases[] = 'USER_DEACTIVATE'; }
        if ($code === 'USER_DEACTIVATE') { $aliases[] = 'USER_DISABLE'; }
        if ($code === 'ROLE_EDIT') { $aliases[] = 'ROLE_UPDATE'; }
        if ($code === 'ROLE_UPDATE') { $aliases[] = 'ROLE_EDIT'; }

        // Courrier aliases
        if (str_starts_with($code, 'CORRESPONDENCE_')) {
            $suffix = substr($code, strlen('CORRESPONDENCE_'));
            $aliases[] = 'COURRIER_' . $suffix;
            $aliases[] = 'MAIL_' . $suffix;
        } elseif (str_starts_with($code, 'COURRIER_')) {
            $suffix = substr($code, strlen('COURRIER_'));
            $aliases[] = 'CORRESPONDENCE_' . $suffix;
            $aliases[] = 'MAIL_' . $suffix;
        } elseif (str_starts_with($code, 'MAIL_')) {
            $suffix = substr($code, strlen('MAIL_'));
            $aliases[] = 'COURRIER_' . $suffix;
            $aliases[] = 'CORRESPONDENCE_' . $suffix;
        }

        // Dossier aliases
        if (str_starts_with($code, 'DOSSIER_')) {
            $suffix = substr($code, strlen('DOSSIER_'));
            $aliases[] = 'CASE_' . $suffix;
        } elseif (str_starts_with($code, 'CASE_')) {
            $suffix = substr($code, strlen('CASE_'));
            $aliases[] = 'DOSSIER_' . $suffix;
        }

        foreach ($this->accessRoles as $role) {
            foreach ($aliases as $candidate) {
                if ($role->hasPermission($candidate)) {
                    return true;
                }
            }
        }
        return false;
    }
    public function getOrganizationUnit(): ?OrganizationUnit { return $this->organizationUnit; }
    public function setOrganizationUnit(?OrganizationUnit $unit): void { $this->organizationUnit = $unit; $this->updatedAt = new \DateTimeImmutable(); }
    public function getStatus(): AccountStatus { return $this->status; }
    public function setStatus(AccountStatus $status): void
    {
        $this->status = $status;
        $this->disabledAt = ($status === AccountStatus::Active) ? null : ($this->disabledAt ?? new \DateTimeImmutable());
        $this->updatedAt = new \DateTimeImmutable();
    }
    public function isActive(): bool { return $this->status === AccountStatus::Active; }
    public function deactivate(): void { $this->setStatus(AccountStatus::Inactive); }
    public function activate(): void { $this->setStatus(AccountStatus::Active); }
    public function lock(): void { $this->setStatus(AccountStatus::Locked); }
    public function recordLogin(): void { $this->lastLoginAt = $this->updatedAt = new \DateTimeImmutable(); }
    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getDisabledAt(): ?\DateTimeImmutable { return $this->disabledAt; }
}
