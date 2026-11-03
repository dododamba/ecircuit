<?php

namespace App\Service;

use App\Entity\CaseEvent;
use App\Entity\Correspondence;
use App\Entity\Document;
use App\Entity\DossierCase;
use App\Entity\OrganizationUnit;
use App\Entity\ReferenceItem;
use App\Entity\Sender;
use App\Entity\User;
use App\Enum\CaseEventType;
use App\Enum\EntryChannel;
use App\Enum\SenderType;
use App\Repository\SenderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class IncomingMailService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReferenceNumberGenerator $referenceGenerator,
        private readonly SenderRepository $senderRepository,
        private readonly DocumentUploader $documentUploader,
        private readonly AuditLogger $auditLogger,
        private readonly WorkflowService $workflowService
    ) {}

    /**
     * Enregistre un courrier entrant et génère automatiquement le dossier associé
     *
     * @param array{
     *     subject: string,
     *     entry_channel: EntryChannel,
     *     received_at: \DateTimeImmutable,
     *     sender_type: SenderType,
     *     sender_name: string,
     *     sender_organisation?: ?string,
     *     sender_phone?: ?string,
     *     sender_email?: ?string,
     *     sender_address?: ?string,
     *     document_type?: ?ReferenceItem,
     *     mail_type?: ?ReferenceItem,
     *     priority?: ?ReferenceItem,
     *     sender_reference?: ?string,
     *     observations?: ?string,
     *     initial_structure?: ?OrganizationUnit
     * } $data
     * @return array{correspondence: Correspondence, case: DossierCase, document: ?Document}
     */
    public function registerIncomingMail(
        array $data,
        User $registeredBy,
        ?UploadedFile $mainFile = null,
        ?Request $request = null
    ): array {
        return $this->entityManager->wrapInTransaction(function () use ($data, $registeredBy, $mainFile, $request): array {
            // 1. Génération de la référence officielle immuable
            $reference = $this->referenceGenerator->generateNextReference();

            // 2. Gestion de l'expéditeur (recherche ou création)
            $sender = $this->senderRepository->findOrCreate(
                $data['sender_type'],
                $data['sender_name'],
                $data['sender_organisation'] ?? null,
                $data['sender_phone'] ?? null,
                $data['sender_email'] ?? null,
                $data['sender_address'] ?? null
            );

            // 3. Création de la correspondance entrante
            $correspondence = new Correspondence(
                $data['subject'],
                $data['entry_channel'],
                $data['received_at']
            );
            $correspondence->assignReference($reference);
            $correspondence->setSender($sender);

            if (!empty($data['document_type'])) {
                $correspondence->setDocumentType($data['document_type']);
            }
            if (!empty($data['mail_type'])) {
                $correspondence->setMailType($data['mail_type']);
            }
            if (!empty($data['priority'])) {
                $correspondence->setPriority($data['priority']);
            }
            if (!empty($data['observations'])) {
                $correspondence->setObservations($data['observations']);
            }

            $correspondence->markRegistered($registeredBy);
            $this->entityManager->persist($correspondence);

            // 4. Téléversement éventuel du document principal
            $document = null;
            if ($mainFile !== null && $mainFile->isValid()) {
                $document = $this->documentUploader->upload(
                    $mainFile,
                    'DOCUMENT_INITIAL',
                    $registeredBy
                );
                $document->setCorrespondence($correspondence);
                $correspondence->setMainDocument($document);
                $this->entityManager->persist($document);
            }

            // 5. Création automatique & atomique du dossier associé (DossierCase)
            $case = new DossierCase(
                $reference,
                $data['subject'],
                $data['entry_channel'],
                $data['received_at']
            );
            $case->setSender($sender);
            $case->setStatusCode('REGISTERED');

            if (!empty($data['document_type'])) {
                $case->setCaseType($data['document_type']);
            }
            if (!empty($data['priority'])) {
                $case->setPriorityCode($data['priority']->getCode());
            }

            $targetStructure = $data['initial_structure'] ?? $registeredBy->getOrganizationUnit();
            if ($targetStructure !== null) {
                $case->setCurrentStructure($targetStructure);
            }

            $case->getCorrespondences()->add($correspondence);
            $correspondence->setCase($case);

            if ($document !== null) {
                $case->addDocument($document);
            }

            $this->entityManager->persist($case);

            // 5b. Initialisation automatique du workflow administratif standard
            $this->workflowService->start($case, null, $registeredBy);

            // 6. Événements de traçabilité métier (Module 9)
            // 6a. Enregistrement officiel du courrier (compatibilité historique CaseRegistered)
            $event = new CaseEvent(
                $case,
                CaseEventType::CaseRegistered,
                'Enregistrement initial du courrier entrant',
                $registeredBy,
                sprintf('Courrier enregistré sous la référence officielle %s par %s.', $reference, $registeredBy->getFullName()),
                action: 'REGISTERED',
                oldValue: 'DRAFT',
                newValue: 'REGISTERED',
                organizationUnit: $registeredBy->getOrganizationUnit(),
                metadata: [
                    'reference' => $reference,
                    'sender' => $sender->getDisplayName(),
                    'channel' => $data['entry_channel']->value,
                    'priority' => $data['priority']?->getCode() ?? 'NORMALE',
                    'has_document' => $document !== null,
                ]
            );
            $case->addEvent($event);
            $this->entityManager->persist($event);

            // 6b. Création du dossier administratif associé
            $createEvent = new CaseEvent(
                $case,
                CaseEventType::DossierCreated,
                'Création du dossier administratif',
                $registeredBy,
                sprintf('Création automatique du dossier pour le courrier %s.', $reference),
                action: 'DOSSIER_CREATED',
                oldValue: null,
                newValue: $reference,
                organizationUnit: $registeredBy->getOrganizationUnit(),
                metadata: [
                    'reference' => $reference,
                    'channel' => $data['entry_channel']->value,
                ]
            );
            $case->addEvent($createEvent);
            $this->entityManager->persist($createEvent);

            // 6c. Document initial joint numérisé le cas échéant
            if ($document !== null) {
                $docEvent = new CaseEvent(
                    $case,
                    CaseEventType::DocumentAdded,
                    sprintf('Document numérisé joint : %s', $document->getOriginalName()),
                    $registeredBy,
                    sprintf('Numérisation du document initial (%s, %d Ko).', $document->getOriginalName(), (int) round($document->getSize() / 1024)),
                    action: 'DOCUMENT_ADDED',
                    oldValue: null,
                    newValue: $document->getOriginalName(),
                    organizationUnit: $registeredBy->getOrganizationUnit(),
                    metadata: [
                        'document_id' => $document->getId(),
                        'document_name' => $document->getOriginalName(),
                        'mime_type' => $document->getMimeType(),
                        'size' => $document->getSize(),
                    ]
                );
                $case->addEvent($docEvent);
                $this->entityManager->persist($docEvent);
            }

            $this->entityManager->flush();

            // 7. Journal d'audit technique de sécurité (Module 10)
            $this->auditLogger->log(
                event: \App\Enum\AuditAction::CourrierRegistered,
                success: true,
                user: $registeredBy,
                request: $request,
                context: [
                    'reference' => $reference,
                    'subject' => $data['subject'],
                    'sender' => $sender->getName(),
                    'entry_channel' => $data['entry_channel']->value,
                ],
                entityType: 'Correspondence',
                entityId: (string) ($correspondence->getId() ?? $reference)
            );

            $this->auditLogger->log(
                event: \App\Enum\AuditAction::DossierCreated,
                success: true,
                user: $registeredBy,
                request: $request,
                context: [
                    'reference' => $reference,
                    'subject' => $data['subject'],
                    'entry_channel' => $data['entry_channel']->value,
                    'structure' => $case->getCurrentStructure()?->getCode(),
                ],
                entityType: 'DossierCase',
                entityId: (string) ($case->getId() ?? $reference)
            );

            if ($document !== null) {
                $this->auditLogger->log(
                    event: \App\Enum\AuditAction::DocumentUploaded,
                    success: true,
                    user: $registeredBy,
                    request: $request,
                    context: [
                        'case_id' => $case->getId(),
                        'case_reference' => $reference,
                        'document_id' => $document->getId(),
                        'original_name' => $document->getOriginalName(),
                        'mime_type' => $document->getMimeType(),
                        'size' => $document->getSize(),
                        'type' => $document->getType(),
                    ],
                    entityType: 'Document',
                    entityId: (string) $document->getId()
                );
            }

            return [
                'correspondence' => $correspondence,
                'case' => $case,
                'document' => $document,
            ];
        });
    }
}
