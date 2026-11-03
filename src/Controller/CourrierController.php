<?php

namespace App\Controller;

use App\Entity\CaseEvent;
use App\Entity\Correspondence;
use App\Entity\Document;
use App\Entity\DossierCase;
use App\Entity\OrganizationUnit;
use App\Entity\ReferenceItem;
use App\Entity\User;
use App\Enum\CaseEventType;
use App\Enum\CorrespondenceStatus;
use App\Enum\EntryChannel;
use App\Enum\ReferenceCategory;
use App\Enum\SenderType;
use App\Repository\CorrespondenceRepository;
use App\Service\AccessScopeService;
use App\Service\AuditLogger;
use App\Service\DocumentUploader;
use App\Service\IncomingMailService;
use App\Service\OutgoingMailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class CourrierController extends AbstractController
{
    public function __construct(
        private readonly AccessScopeService $access,
        private readonly IncomingMailService $incomingMailService,
        private readonly OutgoingMailService $outgoingMailService,
        private readonly CorrespondenceRepository $correspondenceRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentUploader $documentUploader,
        private readonly AuditLogger $auditLogger
    ) {}

    private function user(): User
    {
        /** @var User $user */
        $user = $this->getUser();
        return $user;
    }

    /**
     * Liste et registre officiel des courriers entrants
     */
    #[Route('/courrier/entrant', name: 'courrier_incoming_list', methods: ['GET'])]
    public function incomingList(Request $request): Response
    {
        $this->access->assertPermission($this->user(), 'COURRIER_VIEW');

        $search = $request->query->get('search');
        $channel = $request->query->get('channel');
        $priority = $request->query->get('priority');
        $status = $request->query->get('status');
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $filters = [
            'direction' => 'INCOMING',
            'search' => $search,
            'channel' => $channel,
            'priority' => $priority,
            'status' => $status,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'current_user' => $this->user(),
        ];

        $correspondences = $this->correspondenceRepository->findByFilters($filters, $limit, $offset);
        $totalCount = $this->correspondenceRepository->countByFilters($filters);
        $totalPages = max(1, (int) ceil($totalCount / $limit));
        $stats = $this->correspondenceRepository->getSummaryStats($this->user());

        $priorities = $this->entityManager->getRepository(ReferenceItem::class)->findBy([
            'category' => ReferenceCategory::Priority,
            'active' => true,
        ], ['displayOrder' => 'ASC']);

        return $this->render('courrier/incoming_list.html.twig', [
            'correspondences' => $correspondences,
            'stats' => $stats,
            'total_count' => $totalCount,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'filters' => $filters,
            'channels' => EntryChannel::cases(),
            'priorities' => $priorities,
            'statuses' => CorrespondenceStatus::cases(),
        ]);
    }

    /**
     * Formulaire d'enregistrement d'un courrier entrant avec numérisation
     */
    #[Route('/courrier/entrant/nouveau', name: 'courrier_incoming_new', methods: ['GET', 'POST'])]
    public function incomingNew(Request $request): Response
    {
        $this->access->assertPermission($this->user(), 'COURRIER_CREATE');

        $documentTypes = $this->entityManager->getRepository(ReferenceItem::class)->findBy([
            'category' => ReferenceCategory::DocumentType,
            'active' => true,
        ], ['displayOrder' => 'ASC', 'label' => 'ASC']);

        $mailTypes = $this->entityManager->getRepository(ReferenceItem::class)->findBy([
            'category' => ReferenceCategory::MailType,
            'active' => true,
        ], ['displayOrder' => 'ASC']);

        $priorities = $this->entityManager->getRepository(ReferenceItem::class)->findBy([
            'category' => ReferenceCategory::Priority,
            'active' => true,
        ], ['displayOrder' => 'ASC']);

        $structures = $this->entityManager->getRepository(OrganizationUnit::class)->findBy(
            ['active' => true],
            ['name' => 'ASC']
        );

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('courrier_incoming_new', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $subject = trim((string) $request->request->get('subject'));
            $channelStr = (string) $request->request->get('entry_channel');
            $channel = EntryChannel::tryFrom($channelStr) ?? EntryChannel::Cabinet;

            $receivedAtStr = (string) $request->request->get('received_at');
            try {
                $receivedAt = $receivedAtStr !== '' 
                    ? new \DateTimeImmutable($receivedAtStr) 
                    : new \DateTimeImmutable();
            } catch (\Throwable) {
                $receivedAt = new \DateTimeImmutable();
            }

            $senderTypeStr = (string) $request->request->get('sender_type');
            $senderType = SenderType::tryFrom($senderTypeStr) ?? SenderType::Organisation;
            $senderName = trim((string) $request->request->get('sender_name'));
            $senderOrganisation = trim((string) $request->request->get('sender_organisation'));
            $senderPhone = trim((string) $request->request->get('sender_phone'));
            $senderEmail = trim((string) $request->request->get('sender_email'));
            $senderAddress = trim((string) $request->request->get('sender_address'));

            $rawDocId = $request->request->get('document_type_id');
            $documentTypeId = is_numeric($rawDocId) ? (int) $rawDocId : null;
            $documentType = $documentTypeId ? $this->entityManager->getRepository(ReferenceItem::class)->find($documentTypeId) : null;

            $rawMailId = $request->request->get('mail_type_id');
            $mailTypeId = is_numeric($rawMailId) ? (int) $rawMailId : null;
            $mailType = $mailTypeId ? $this->entityManager->getRepository(ReferenceItem::class)->find($mailTypeId) : null;

            $rawPriorityId = $request->request->get('priority_id');
            $priorityId = is_numeric($rawPriorityId) ? (int) $rawPriorityId : null;
            $priority = $priorityId ? $this->entityManager->getRepository(ReferenceItem::class)->find($priorityId) : null;

            $rawStructId = $request->request->get('initial_structure_id');
            $structureId = is_numeric($rawStructId) ? (int) $rawStructId : null;
            $initialStructure = $structureId ? $this->entityManager->getRepository(OrganizationUnit::class)->find($structureId) : null;

            $observations = trim((string) $request->request->get('observations'));
            $senderReference = trim((string) $request->request->get('sender_reference'));

            // Validation minimale
            if ($subject === '' || $senderName === '') {
                $this->addFlash('error', 'L’objet du courrier et le nom de l’expéditeur sont obligatoires.');
                return $this->render('courrier/incoming_new.html.twig', [
                    'document_types' => $documentTypes,
                    'mail_types' => $mailTypes,
                    'priorities' => $priorities,
                    'structures' => $structures,
                    'channels' => EntryChannel::cases(),
                    'sender_types' => SenderType::cases(),
                ]);
            }

            /** @var UploadedFile|null $uploadedFile */
            $uploadedFile = $request->files->get('main_document');

            try {
                $result = $this->incomingMailService->registerIncomingMail(
                    [
                        'subject' => $subject,
                        'entry_channel' => $channel,
                        'received_at' => $receivedAt,
                        'sender_type' => $senderType,
                        'sender_name' => $senderName,
                        'sender_organisation' => $senderOrganisation !== '' ? $senderOrganisation : null,
                        'sender_phone' => $senderPhone !== '' ? $senderPhone : null,
                        'sender_email' => $senderEmail !== '' ? $senderEmail : null,
                        'sender_address' => $senderAddress !== '' ? $senderAddress : null,
                        'document_type' => $documentType,
                        'mail_type' => $mailType,
                        'priority' => $priority,
                        'sender_reference' => $senderReference !== '' ? $senderReference : null,
                        'observations' => $observations !== '' ? $observations : null,
                        'initial_structure' => $initialStructure,
                    ],
                    $this->user(),
                    $uploadedFile,
                    $request
                );

                /** @var Correspondence $correspondence */
                $correspondence = $result['correspondence'];

                $this->addFlash('success', sprintf(
                    'Le courrier entrant a été enregistré avec succès sous la référence %s.',
                    $correspondence->getReference()
                ));

                return $this->redirectToRoute('courrier_show', ['id' => $correspondence->getId()]);
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Erreur lors de l’enregistrement : ' . $e->getMessage());
            }
        }

        return $this->render('courrier/incoming_new.html.twig', [
            'document_types' => $documentTypes,
            'mail_types' => $mailTypes,
            'priorities' => $priorities,
            'structures' => $structures,
            'channels' => EntryChannel::cases(),
            'sender_types' => SenderType::cases(),
        ]);
    }

    /**
     * Liste et registre officiel des courriers sortants (S2.09)
     */
    #[Route('/courrier/sortant', name: 'courrier_outgoing_list', methods: ['GET'])]
    public function outgoingList(Request $request): Response
    {
        $this->access->assertPermission($this->user(), 'COURRIER_VIEW');

        $search = $request->query->get('search');
        $status = $request->query->get('status');
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $filters = [
            'direction' => 'OUTGOING',
            'search' => $search,
            'status' => $status,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'current_user' => $this->user(),
        ];

        $correspondences = $this->correspondenceRepository->findByFilters($filters, $limit, $offset);
        $totalCount = $this->correspondenceRepository->countByFilters($filters);
        $totalPages = max(1, (int) ceil($totalCount / $limit));

        return $this->render('courrier/outgoing_list.html.twig', [
            'correspondences' => $correspondences,
            'total_count' => $totalCount,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'filters' => $filters,
            'statuses' => [
                CorrespondenceStatus::Draft,
                CorrespondenceStatus::PendingValidation,
                CorrespondenceStatus::Validated,
                CorrespondenceStatus::ReadyToSend,
                CorrespondenceStatus::Sent,
                CorrespondenceStatus::Cancelled,
            ],
        ]);
    }

    /**
     * Création d'un courrier sortant rattaché ou non à un dossier (S2.09)
     */
    #[Route('/courrier/sortant/nouveau', name: 'courrier_outgoing_new', methods: ['GET', 'POST'])]
    public function outgoingNew(Request $request): Response
    {
        $user = $this->user();
        if (!$this->access->hasPermission($user, 'COURRIER_CREATE') && !$this->access->hasPermission($user, 'DOSSIER_UPDATE')) {
            throw $this->createAccessDeniedException('Permission requise pour préparer un courrier sortant.');
        }

        $caseId = $request->query->getInt('case_id', (int) $request->request->get('case_id', 0));
        $linkedCase = $caseId > 0 ? $this->entityManager->getRepository(DossierCase::class)->find($caseId) : null;
        if ($linkedCase !== null) {
            $this->access->assertDossierAccess($user, $linkedCase);
        }

        $documentTypes = $this->entityManager->getRepository(ReferenceItem::class)->findBy([
            'category' => ReferenceCategory::DocumentType,
            'active' => true,
        ], ['displayOrder' => 'ASC', 'label' => 'ASC']);

        $mailTypes = $this->entityManager->getRepository(ReferenceItem::class)->findBy([
            'category' => ReferenceCategory::MailType,
            'active' => true,
        ], ['displayOrder' => 'ASC']);

        $priorities = $this->entityManager->getRepository(ReferenceItem::class)->findBy([
            'category' => ReferenceCategory::Priority,
            'active' => true,
        ], ['displayOrder' => 'ASC']);

        $structures = $this->entityManager->getRepository(OrganizationUnit::class)->findBy(
            ['active' => true],
            ['name' => 'ASC']
        );

        $availableCases = $this->entityManager->getRepository(DossierCase::class)->findByFilters(
            ['current_user' => $user],
            100,
            0
        );

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('courrier_outgoing_new', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $subject = trim((string) $request->request->get('subject'));
            $recipientName = trim((string) $request->request->get('recipient_name'));
            $recipientOrganisation = trim((string) $request->request->get('recipient_organisation'));
            $dispatchMethod = trim((string) $request->request->get('dispatch_method', 'REMISE_OFFICIELLE'));
            $observations = trim((string) $request->request->get('observations'));

            $rawDocId = $request->request->get('document_type_id');
            $documentType = is_numeric($rawDocId) ? $this->entityManager->getRepository(ReferenceItem::class)->find((int) $rawDocId) : null;

            $rawMailId = $request->request->get('mail_type_id');
            $mailType = is_numeric($rawMailId) ? $this->entityManager->getRepository(ReferenceItem::class)->find((int) $rawMailId) : null;

            $rawPriorityId = $request->request->get('priority_id');
            $priority = is_numeric($rawPriorityId) ? $this->entityManager->getRepository(ReferenceItem::class)->find((int) $rawPriorityId) : null;

            $rawStructId = $request->request->get('issuing_structure_id');
            $issuingStructure = is_numeric($rawStructId)
                ? $this->entityManager->getRepository(OrganizationUnit::class)->find((int) $rawStructId)
                : ($user->getOrganizationUnit() ?? $linkedCase?->getCurrentStructure());

            $statusStr = (string) $request->request->get('status', CorrespondenceStatus::Draft->value);
            $initialStatus = CorrespondenceStatus::tryFrom($statusStr) ?? CorrespondenceStatus::Draft;

            if ($subject === '' || $recipientName === '') {
                $this->addFlash('error', 'L’objet du courrier sortant et le nom du destinataire sont obligatoires.');
                return $this->render('courrier/outgoing_new.html.twig', [
                    'linked_case' => $linkedCase,
                    'available_cases' => $availableCases,
                    'document_types' => $documentTypes,
                    'mail_types' => $mailTypes,
                    'priorities' => $priorities,
                    'structures' => $structures,
                ]);
            }

            /** @var UploadedFile|null $uploadedFile */
            $uploadedFile = $request->files->get('main_document');

            try {
                $result = $this->outgoingMailService->createOutgoingMail(
                    [
                        'case' => $linkedCase,
                        'subject' => $subject,
                        'recipient_name' => $recipientName,
                        'recipient_organisation' => $recipientOrganisation !== '' ? $recipientOrganisation : null,
                        'dispatch_method' => $dispatchMethod,
                        'issuing_structure' => $issuingStructure,
                        'document_type' => $documentType,
                        'mail_type' => $mailType,
                        'priority' => $priority,
                        'observations' => $observations !== '' ? $observations : null,
                        'status' => $initialStatus,
                    ],
                    $user,
                    $uploadedFile,
                    $request
                );

                /** @var Correspondence $correspondence */
                $correspondence = $result['correspondence'];

                $this->addFlash('success', sprintf(
                    'Le courrier sortant a été enregistré sous la référence %s.',
                    $correspondence->getReference()
                ));

                return $this->redirectToRoute('courrier_outgoing_show', ['id' => $correspondence->getId()]);
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Erreur lors de la création du courrier sortant : ' . $e->getMessage());
            }
        }

        return $this->render('courrier/outgoing_new.html.twig', [
            'linked_case' => $linkedCase,
            'available_cases' => $availableCases,
            'document_types' => $documentTypes,
            'mail_types' => $mailTypes,
            'priorities' => $priorities,
            'structures' => $structures,
        ]);
    }

    /**
     * Consultation détaillée d'un courrier sortant (S2.09)
     */
    #[Route('/courrier/sortant/{id}', name: 'courrier_outgoing_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function outgoingShow(Correspondence $correspondence): Response
    {
        $this->access->assertPermission($this->user(), 'COURRIER_VIEW');
        $this->access->assertCorrespondenceAccess($this->user(), $correspondence);

        return $this->render('courrier/outgoing_show.html.twig', [
            'correspondence' => $correspondence,
            'case' => $correspondence->getCase(),
            'allowed_transitions' => $this->outgoingMailService->getAllowedTransitions($correspondence),
        ]);
    }

    /**
     * Transition du statut d'un courrier sortant (DRAFT -> PENDING_VALIDATION -> VALIDATED -> READY_TO_SEND -> SENT)
     */
    #[Route('/courrier/sortant/{id}/status', name: 'courrier_outgoing_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function outgoingStatus(Correspondence $correspondence, Request $request): Response
    {
        $user = $this->user();
        if (!$this->access->hasPermission($user, 'COURRIER_CREATE') && !$this->access->hasPermission($user, 'DOSSIER_UPDATE')) {
            throw $this->createAccessDeniedException('Permission requise pour faire évoluer le courrier sortant.');
        }
        $this->access->assertCorrespondenceAccess($user, $correspondence);

        if (!$this->isCsrfTokenValid('outgoing_status_' . $correspondence->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $statusStr = (string) $request->request->get('target_status');
        $targetStatus = CorrespondenceStatus::tryFrom($statusStr);
        if ($targetStatus === null) {
            $this->addFlash('error', 'Statut cible invalide.');
            return $this->redirectToRoute('courrier_outgoing_show', ['id' => $correspondence->getId()]);
        }

        $comment = trim((string) $request->request->get('comment', ''));

        try {
            $this->outgoingMailService->transitionStatus(
                $correspondence,
                $targetStatus,
                $user,
                $comment !== '' ? $comment : null,
                $request
            );
            $this->addFlash('success', sprintf('Statut du courrier sortant %s mis à jour : %s.', $correspondence->getReference(), $targetStatus->label()));
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('courrier_outgoing_show', ['id' => $correspondence->getId()]);
    }

    /**
     * Consultation détaillée d'un courrier
     */
    #[Route('/courrier/{id}', name: 'courrier_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Correspondence $correspondence): Response
    {
        $this->access->assertPermission($this->user(), 'COURRIER_VIEW');
        $this->access->assertCorrespondenceAccess($this->user(), $correspondence);

        return $this->render('courrier/show.html.twig', [
            'correspondence' => $correspondence,
            'case' => $correspondence->getCase(),
        ]);
    }

    /**
     * Fiche suiveuse administrative officielle (format imprimable)
     */
    #[Route('/courrier/{id}/fiche-suiveuse', name: 'courrier_routing_slip', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function routingSlip(Correspondence $correspondence): Response
    {
        $this->access->assertPermission($this->user(), 'COURRIER_VIEW');
        $this->access->assertCorrespondenceAccess($this->user(), $correspondence);

        return $this->render('courrier/routing_slip.html.twig', [
            'correspondence' => $correspondence,
            'case' => $correspondence->getCase(),
        ]);
    }

    /**
     * Récépissé d'enregistrement officiel délivré à l'usager/expéditeur
     */
    #[Route('/courrier/{id}/recepisse', name: 'courrier_receipt', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function receipt(Correspondence $correspondence): Response
    {
        $this->access->assertPermission($this->user(), 'COURRIER_VIEW');
        $this->access->assertCorrespondenceAccess($this->user(), $correspondence);

        return $this->render('courrier/receipt.html.twig', [
            'correspondence' => $correspondence,
            'case' => $correspondence->getCase(),
        ]);
    }

    /**
     * Téléchargement / Visualisation sécurisée d'un document
     */
    #[Route('/document/{id}/download', name: 'document_download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function downloadDocument(Document $document, Request $request): Response
    {
        $user = $this->user();
        if (!$this->access->hasPermission($user, 'COURRIER_VIEW') && 
            !$this->access->hasPermission($user, 'DOSSIER_VIEW') && 
            !$this->access->hasPermission($user, 'DOCUMENT_VIEW') && 
            !$this->access->hasPermission($user, 'DOCUMENT_DOWNLOAD')) {
            $request->attributes->set('_audit_access_denied_logged', true);
            $this->auditLogger->log(
                event: \App\Enum\AuditAction::DocumentDownloaded,
                success: false,
                user: $user,
                request: $request,
                context: [
                    'document_id' => $document->getId(),
                    'original_name' => $document->getOriginalName(),
                    'case_id' => $document->getCase()?->getId(),
                    'reason' => 'missing_document_download_permission',
                ],
                entityType: 'Document',
                entityId: (string) $document->getId(),
                result: \App\Enum\AuditResult::Denied,
                statusCode: 403
            );
            throw $this->createAccessDeniedException('Accès refusé au document.');
        }

        if (!$this->access->canAccessDocument($user, $document)) {
            $request->attributes->set('_audit_access_denied_logged', true);
            $this->auditLogger->log(
                event: \App\Enum\AuditAction::DocumentDownloaded,
                success: false,
                user: $user,
                request: $request,
                context: [
                    'document_id' => $document->getId(),
                    'original_name' => $document->getOriginalName(),
                    'case_id' => $document->getCase()?->getId(),
                    'user_structure' => $user->getOrganizationUnit()?->getCode(),
                    'case_structure' => $document->getCase()?->getCurrentStructure()?->getCode(),
                    'reason' => 'inter_structure_access_denied',
                ],
                entityType: 'Document',
                entityId: (string) $document->getId(),
                result: \App\Enum\AuditResult::Denied,
                statusCode: 403
            );
            throw $this->createAccessDeniedException('Accès inter-structures refusé au document.');
        }

        $filePath = $this->documentUploader->getAbsolutePath($document);
        if (!file_exists($filePath)) {
            $this->auditLogger->log(
                event: \App\Enum\AuditAction::DocumentDownloaded,
                success: false,
                user: $user,
                request: $request,
                context: [
                    'document_id' => $document->getId(),
                    'original_name' => $document->getOriginalName(),
                    'reason' => 'file_not_found_on_storage',
                ],
                entityType: 'Document',
                entityId: (string) $document->getId(),
                result: \App\Enum\AuditResult::Failure,
                statusCode: 404
            );
            throw $this->createNotFoundException('Le fichier physique demandé est introuvable sur le serveur.');
        }

        // Enregistrement de l'événement de traçabilité métier (DOCUMENT_DOWNLOADED - Module 9)
        if ($document->getCase() !== null) {
            $case = $document->getCase();
            $event = new CaseEvent(
                $case,
                CaseEventType::DocumentDownloaded,
                sprintf('Document téléchargé : %s', $document->getOriginalName()),
                $user,
                sprintf('Téléchargement du document "%s" par %s.', $document->getOriginalName(), $user->getFullName()),
                action: 'DOCUMENT_DOWNLOADED',
                oldValue: null,
                newValue: $document->getOriginalName(),
                organizationUnit: $user->getOrganizationUnit(),
                metadata: [
                    'document_id' => $document->getId(),
                    'document_name' => $document->getOriginalName(),
                    'mime_type' => $document->getMimeType(),
                ]
            );
            $case->addEvent($event);
            $this->entityManager->persist($event);
            $this->entityManager->flush();
        }

        // Enregistrement du journal d'audit technique de sécurité (Module 10)
        $this->auditLogger->log(
            event: \App\Enum\AuditAction::DocumentDownloaded,
            success: true,
            user: $user,
            request: $request,
            context: [
                'document_id' => $document->getId(),
                'original_name' => $document->getOriginalName(),
                'mime_type' => $document->getMimeType(),
                'size' => $document->getSize(),
                'case_id' => $document->getCase()?->getId(),
                'case_reference' => $document->getCase()?->getReference(),
            ],
            entityType: 'Document',
            entityId: (string) $document->getId(),
            result: \App\Enum\AuditResult::Success,
            statusCode: 200
        );

        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', $document->getMimeType());
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $document->getOriginalName()
        );

        return $response;
    }
}
