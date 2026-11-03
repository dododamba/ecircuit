<?php

namespace App\Service;

use App\Entity\CaseEvent;
use App\Entity\Correspondence;
use App\Entity\Document;
use App\Entity\DossierCase;
use App\Entity\OrganizationUnit;
use App\Entity\ReferenceItem;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Enum\CaseEventType;
use App\Enum\CorrespondenceStatus;
use App\Enum\EntryChannel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class OutgoingMailService
{
    private const ALLOWED_TRANSITIONS = [
        CorrespondenceStatus::Draft->value => [
            CorrespondenceStatus::PendingValidation,
            CorrespondenceStatus::Validated,
            CorrespondenceStatus::ReadyToSend,
            CorrespondenceStatus::Cancelled,
        ],
        CorrespondenceStatus::PendingValidation->value => [
            CorrespondenceStatus::Validated,
            CorrespondenceStatus::Draft,
            CorrespondenceStatus::Cancelled,
        ],
        CorrespondenceStatus::Validated->value => [
            CorrespondenceStatus::ReadyToSend,
            CorrespondenceStatus::Sent,
            CorrespondenceStatus::Cancelled,
        ],
        CorrespondenceStatus::ReadyToSend->value => [
            CorrespondenceStatus::Sent,
            CorrespondenceStatus::Cancelled,
        ],
        CorrespondenceStatus::Sent->value => [],
        CorrespondenceStatus::Cancelled->value => [],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReferenceNumberGenerator $referenceGenerator,
        private readonly DocumentUploader $documentUploader,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Crée et enregistre un courrier sortant (rattaché ou non à un dossier existant)
     *
     * @param array<string, mixed> $data
     * @return array{correspondence: Correspondence, document: ?Document}
     */
    public function createOutgoingMail(
        array $data,
        User $actor,
        ?UploadedFile $uploadedFile = null,
        ?Request $request = null
    ): array {
        return $this->entityManager->wrapInTransaction(function () use ($data, $actor, $uploadedFile, $request): array {
            $now = $data['created_at'] ?? new \DateTimeImmutable();
            if (!$now instanceof \DateTimeImmutable) {
                $now = new \DateTimeImmutable();
            }

            /** @var DossierCase|null $case */
            $case = ($data['case'] ?? null) instanceof DossierCase ? $data['case'] : null;

            /** @var EntryChannel $channel */
            $channel = ($data['entry_channel'] ?? null) instanceof EntryChannel
                ? $data['entry_channel']
                : ($case?->getEntryChannel() ?? EntryChannel::SecretariatGeneral);

            $subject = trim((string) ($data['subject'] ?? ''));
            $recipientName = trim((string) ($data['recipient_name'] ?? ''));
            $recipientOrganisation = isset($data['recipient_organisation']) ? trim((string) $data['recipient_organisation']) : null;
            $dispatchMethod = isset($data['dispatch_method']) && trim((string) $data['dispatch_method']) !== ''
                ? trim((string) $data['dispatch_method'])
                : 'REMISE_OFFICIELLE';

            /** @var OrganizationUnit|null $issuingStructure */
            $issuingStructure = ($data['issuing_structure'] ?? null) instanceof OrganizationUnit
                ? $data['issuing_structure']
                : ($actor->getOrganizationUnit() ?? $case?->getCurrentStructure());

            /** @var CorrespondenceStatus $initialStatus */
            $initialStatus = ($data['status'] ?? null) instanceof CorrespondenceStatus
                ? $data['status']
                : CorrespondenceStatus::Draft;

            $reference = $this->referenceGenerator->generateNextOutgoingReference((int) $now->format('Y'));

            $correspondence = new Correspondence($subject, $channel, $now);
            $correspondence->assignReference($reference);
            $correspondence->setStatus($initialStatus);
            $correspondence->setRegisteredBy($actor);
            $correspondence->setRegisteredAt($now);
            $correspondence->setRecipientName($recipientName !== '' ? $recipientName : null);
            $correspondence->setRecipientOrganisation($recipientOrganisation !== '' ? $recipientOrganisation : null);
            $correspondence->setDispatchMethod($dispatchMethod);
            $correspondence->setIssuingStructure($issuingStructure);
            $correspondence->setObservations($data['observations'] ?? null);

            if (($data['document_type'] ?? null) instanceof ReferenceItem) {
                $correspondence->setDocumentType($data['document_type']);
            }
            if (($data['mail_type'] ?? null) instanceof ReferenceItem) {
                $correspondence->setMailType($data['mail_type']);
            }
            if (($data['priority'] ?? null) instanceof ReferenceItem) {
                $correspondence->setPriority($data['priority']);
            }

            if ($case !== null) {
                $correspondence->setCase($case);
                $case->addCorrespondence($correspondence);
            }

            if ($initialStatus === CorrespondenceStatus::Sent) {
                $correspondence->markSent($now);
            } elseif (in_array($initialStatus, [CorrespondenceStatus::Validated, CorrespondenceStatus::ReadyToSend], true)) {
                $correspondence->validate($actor);
                $correspondence->setStatus($initialStatus);
            }

            $this->entityManager->persist($correspondence);

            $document = null;
            if ($uploadedFile instanceof UploadedFile) {
                $document = $this->documentUploader->upload(
                    $uploadedFile,
                    'COURRIER_SORTANT',
                    $actor
                );
                $document->setCorrespondence($correspondence);
                if ($case !== null) {
                    $document->setCase($case);
                    $case->addDocument($document);
                }
                $correspondence->setMainDocument($document);
                $this->entityManager->persist($document);
            }

            if ($case !== null) {
                $eventType = $initialStatus === CorrespondenceStatus::Sent
                    ? CaseEventType::OutgoingMailSent
                    : CaseEventType::OutgoingMailCreated;
                $eventLabel = $initialStatus === CorrespondenceStatus::Sent
                    ? sprintf('Courrier sortant expédié (%s)', $reference)
                    : sprintf('Courrier sortant préparé (%s)', $reference);

                $event = new CaseEvent(
                    $case,
                    $eventType,
                    $eventLabel,
                    $actor,
                    sprintf(
                        'Courrier sortant "%s" à destination de %s (%s). Statut : %s.',
                        $subject,
                        $recipientName ?: 'Destinataire non précisé',
                        $recipientOrganisation ?: 'Externe',
                        $initialStatus->label()
                    ),
                    action: $eventType->value,
                    oldValue: null,
                    newValue: $reference,
                    organizationUnit: $issuingStructure,
                    metadata: [
                        'correspondence_reference' => $reference,
                        'recipient_name' => $recipientName,
                        'recipient_organisation' => $recipientOrganisation,
                        'dispatch_method' => $dispatchMethod,
                        'status' => $initialStatus->value,
                    ]
                );
                $case->addEvent($event);
                $this->entityManager->persist($event);
            }

            $this->entityManager->flush();

            $this->auditLogger->log(
                AuditAction::OutgoingMailCreated,
                true,
                $actor,
                $request,
                [
                    'correspondence_id' => $correspondence->getId(),
                    'reference' => $reference,
                    'case_id' => $case?->getId(),
                    'case_reference' => $case?->getReference(),
                    'recipient_name' => $recipientName,
                    'recipient_organisation' => $recipientOrganisation,
                    'dispatch_method' => $dispatchMethod,
                    'issuing_structure' => $issuingStructure?->getCode(),
                    'status' => $initialStatus->value,
                    'has_document' => $document !== null,
                ],
                entityType: 'Correspondence',
                entityId: (string) $correspondence->getId()
            );

            return [
                'correspondence' => $correspondence,
                'document' => $document,
            ];
        });
    }

    /**
     * Fait évoluer le statut d'un courrier sortant (DRAFT -> PENDING_VALIDATION -> VALIDATED -> READY_TO_SEND -> SENT)
     */
    public function transitionStatus(
        Correspondence $correspondence,
        CorrespondenceStatus $targetStatus,
        User $actor,
        ?string $comment = null,
        ?Request $request = null
    ): void {
        $currentStatus = $correspondence->getStatus();
        $allowed = self::ALLOWED_TRANSITIONS[$currentStatus->value] ?? [];

        if (!in_array($targetStatus, $allowed, true)) {
            throw new \DomainException(sprintf(
                'Transition de statut non autorisée de "%s" vers "%s".',
                $currentStatus->label(),
                $targetStatus->label()
            ));
        }

        if ($targetStatus === CorrespondenceStatus::Sent) {
            $correspondence->markSent();
        } elseif ($targetStatus === CorrespondenceStatus::Validated) {
            $correspondence->validate($actor);
        } else {
            $correspondence->setStatus($targetStatus);
        }

        if ($comment !== null && trim($comment) !== '') {
            $existingObs = $correspondence->getObservations();
            $correspondence->setObservations(
                ($existingObs ? $existingObs . "\n" : '') . sprintf('[%s - %s] %s', (new \DateTimeImmutable())->format('d/m/Y H:i'), $actor->getFullName(), trim($comment))
            );
        }

        $case = $correspondence->getCase();
        if ($case !== null) {
            $eventType = $targetStatus === CorrespondenceStatus::Sent
                ? CaseEventType::OutgoingMailSent
                : CaseEventType::CaseUpdated;
            $label = $targetStatus === CorrespondenceStatus::Sent
                ? sprintf('Courrier sortant expédié (%s)', $correspondence->getReference())
                : sprintf('Statut du courrier sortant %s : %s', $correspondence->getReference(), $targetStatus->label());

            $event = new CaseEvent(
                $case,
                $eventType,
                $label,
                $actor,
                $comment ?: sprintf('Passage du courrier sortant %s au statut "%s".', $correspondence->getReference(), $targetStatus->label()),
                action: $eventType->value,
                oldValue: $currentStatus->value,
                newValue: $targetStatus->value,
                organizationUnit: $actor->getOrganizationUnit() ?? $correspondence->getIssuingStructure(),
                metadata: [
                    'correspondence_id' => $correspondence->getId(),
                    'correspondence_reference' => $correspondence->getReference(),
                    'old_status' => $currentStatus->value,
                    'new_status' => $targetStatus->value,
                ]
            );
            $case->addEvent($event);
            $this->entityManager->persist($event);
        }

        $this->entityManager->flush();

        $auditAction = $targetStatus === CorrespondenceStatus::Sent
            ? AuditAction::OutgoingMailSent
            : AuditAction::OutgoingMailUpdated;

        $this->auditLogger->log(
            $auditAction,
            true,
            $actor,
            $request,
            [
                'correspondence_id' => $correspondence->getId(),
                'reference' => $correspondence->getReference(),
                'case_id' => $case?->getId(),
                'old_status' => $currentStatus->value,
                'new_status' => $targetStatus->value,
                'comment' => $comment,
            ],
            entityType: 'Correspondence',
            entityId: (string) $correspondence->getId()
        );
    }

    /**
     * @return list<CorrespondenceStatus>
     */
    public function getAllowedTransitions(Correspondence $correspondence): array
    {
        return self::ALLOWED_TRANSITIONS[$correspondence->getStatus()->value] ?? [];
    }
}
