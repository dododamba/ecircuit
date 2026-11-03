<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuditAction;
use App\Enum\AuditResult;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_logs')]
#[ORM\Index(name: 'idx_audit_logs_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_audit_logs_action', columns: ['action'])]
#[ORM\Index(name: 'idx_audit_logs_event', columns: ['event'])]
#[ORM\Index(name: 'idx_audit_logs_entity', columns: ['entity_type', 'entity_id'])]
#[ORM\Index(name: 'idx_audit_logs_result', columns: ['result'])]
#[ORM\Index(name: 'idx_audit_logs_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_audit_logs_route', columns: ['route'])]
#[ORM\HasLifecycleCallbacks]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'SET NULL', nullable: true)]
    private ?User $user = null;

    #[ORM\Column(length: 80)]
    private string $event;

    #[ORM\Column(length: 100, options: ['default' => ''])]
    private string $action;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $entityType = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $entityId = null;

    #[ORM\Column(length: 20, options: ['default' => 'SUCCESS'])]
    private string $result = 'SUCCESS';

    #[ORM\Column]
    private bool $success;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $route = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $httpMethod = null;

    #[ORM\Column(nullable: true)]
    private ?int $statusCode = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $requestId = null;

    #[ORM\Column(type: 'json')]
    private array $context = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $event,
        bool $success = true,
        ?User $user = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        array $context = [],
        ?string $action = null,
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $route = null,
        ?string $httpMethod = null,
        ?string $result = null,
        ?int $statusCode = null,
        ?string $requestId = null,
        ?\DateTimeImmutable $createdAt = null
    ) {
        $this->event = $event;
        $this->action = $action ?? $event;
        $this->user = $user;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
        $this->context = $context;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->route = $route;
        $this->httpMethod = $httpMethod;
        $this->statusCode = $statusCode;
        $this->requestId = $requestId;

        if ($result !== null) {
            $this->result = strtoupper($result);
            $this->success = ($this->result === 'SUCCESS');
        } else {
            $this->success = $success;
            $this->result = $success ? 'SUCCESS' : 'FAILURE';
        }

        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function preventUpdate(): void
    {
        throw new \LogicException('Les journaux d\'audit de sécurité (AuditLog) sont strictement immuables et ne peuvent pas être modifiés.');
    }

    #[ORM\PreRemove]
    public function preventRemove(): void
    {
        throw new \LogicException('Les journaux d\'audit de sécurité (AuditLog) sont strictement immuables et ne peuvent pas être supprimés.');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getEvent(): string
    {
        return $this->event;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getAuditAction(): ?AuditAction
    {
        return AuditAction::tryFrom($this->action) ?? AuditAction::tryFrom($this->event);
    }

    public function getActionLabel(): string
    {
        return $this->getAuditAction()?->label() ?? $this->action;
    }

    public function getActionCategory(): string
    {
        return $this->getAuditAction()?->category() ?? 'TECHNIQUE';
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function getEntityId(): ?string
    {
        return $this->entityId;
    }

    public function getResult(): string
    {
        return $this->result;
    }

    public function getAuditResult(): AuditResult
    {
        return AuditResult::tryFrom($this->result) ?? ($this->success ? AuditResult::Success : AuditResult::Failure);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getRoute(): ?string
    {
        return $this->route;
    }

    public function getHttpMethod(): ?string
    {
        return $this->httpMethod;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Alias context / metadata
     */
    public function getMetadata(): array
    {
        return $this->context;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Alias date / timestamp
     */
    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
