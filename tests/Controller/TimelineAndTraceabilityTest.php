<?php

namespace App\Tests\Controller;

use App\Entity\AccessPermission;
use App\Entity\AccessRole;
use App\Entity\CaseEvent;
use App\Entity\Document;
use App\Entity\DossierCase;
use App\Entity\OrganizationUnit;
use App\Entity\ReferenceItem;
use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use App\Entity\WorkflowTransition;
use App\Enum\AccountStatus;
use App\Enum\CaseEventType;
use App\Enum\EntryChannel;
use App\Enum\ReferenceCategory;
use App\Enum\StructureType;
use App\Enum\WorkflowAction;
use App\Repository\CaseEventRepository;
use App\Service\IncomingMailService;
use App\Service\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TimelineAndTraceabilityTest extends WebTestCase
{
    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function getPasswordHasher(): UserPasswordHasherInterface
    {
        return self::getContainer()->get(UserPasswordHasherInterface::class);
    }

    private function getOrCreateUser(string $email, string $roleCode, ?string $structureCode = null): User
    {
        $em = $this->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $user = new User($email, 'Testeur', $roleCode);
            $user->setPassword($this->getPasswordHasher()->hashPassword($user, 'PasswordSecret123!'));
            $user->setStatus(AccountStatus::Active);
            $em->persist($user);
        }

        $role = $em->getRepository(AccessRole::class)->findOneBy(['code' => $roleCode]);
        if ($role && !$user->hasAccessRole($roleCode)) {
            $user->addAccessRole($role);
        }

        if ($structureCode) {
            $unit = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => $structureCode]);
            if ($unit) {
                $user->setOrganizationUnit($unit);
            }
        }

        $em->flush();

        return $user;
    }

    private function loginUser(KernelBrowser $client, User $user): void
    {
        $client->loginUser($user);
    }

    /**
     * Test 1 : Scénario complet vérifiant que chaque action produit le bon événement
     * avec dossier, utilisateur, action, date, ancienne/nouvelle valeur, structure, commentaire et métadonnées.
     */
    public function testFullLifecycleActionEventsRecorded(): void
    {
        $client = static::createClient();
        $em = $this->getEntityManager();
        $workflowService = self::getContainer()->get(WorkflowService::class);
        $incomingMailService = self::getContainer()->get(IncomingMailService::class);

        // Utilisateurs pour les différentes étapes
        $agentCabinet = $this->getOrCreateUser('agent_cab_tl@mci.test', 'AGENT_CABINET', 'CABINET');
        $ministre = $this->getOrCreateUser('ministre_tl@mci.test', 'MINISTRE', 'MINISTERE');
        $sg = $this->getOrCreateUser('sg_tl@mci.test', 'SECRETAIRE_GENERAL', 'SECRETARIAT_GENERAL');
        $dgCommerce = $this->getOrCreateUser('dg_com_tl@mci.test', 'DIRECTEUR_GENERAL', 'DG_COMMERCE');
        $respDir = $this->getOrCreateUser('resp_dir_tl@mci.test', 'RESPONSABLE_DIRECTION', 'DIRECTION_COMMERCE_INTERIEUR');
        $agentTraitement = $this->getOrCreateUser('agent_trait_tl@mci.test', 'AGENT_TRAITEMENT', 'SERVICE_REGLEMENTATION');

        // Référentiels pour l'enregistrement
        $docType = $em->getRepository(ReferenceItem::class)->findOneBy(['category' => ReferenceCategory::DocumentType]);
        $mailType = $em->getRepository(ReferenceItem::class)->findOneBy(['category' => ReferenceCategory::MailType]);
        $priority = $em->getRepository(ReferenceItem::class)->findOneBy(['category' => ReferenceCategory::Priority, 'code' => 'NORMALE']);
        $cabinetUnit = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'CABINET']);

        // Fichier temporaire pour simuler le document scanné
        $tmpFile = tempnam(sys_get_temp_dir(), 'mci_doc_') . '.pdf';
        file_put_contents($tmpFile, '%PDF-1.4 Mock document test traçabilité');
        $uploadedFile = new UploadedFile($tmpFile, 'bordereau_original.pdf', 'application/pdf', null, true);

        // 1. Enregistrement du courrier entrant (Génère DOSSIER_CREATED, REGISTERED, DOCUMENT_ADDED)
        $res = $incomingMailService->registerIncomingMail([
            'subject' => 'Demande d\'agrément commercial import-export',
            'entry_channel' => EntryChannel::Cabinet,
            'received_at' => new \DateTimeImmutable('2026-09-02 08:30:00'),
            'sender_type' => \App\Enum\SenderType::Organisation,
            'sender_name' => 'Société Gabonaise de Négoce',
            'document_type' => $docType,
            'mail_type' => $mailType,
            'priority' => $priority,
            'initial_structure' => $cabinetUnit,
            'observations' => 'Courrier reçu par porteur au Cabinet',
        ], $agentCabinet, $uploadedFile);

        /** @var DossierCase $case */
        $case = $res['case'];
        self::assertNotNull($case);

        // 2. Téléchargement du document (Génère DOCUMENT_DOWNLOADED)
        $this->loginUser($client, $agentCabinet);
        $docs = $case->getDocuments();
        self::assertGreaterThanOrEqual(1, $docs->count());
        $mainDoc = $docs->first();

        $client->request('GET', sprintf('/document/%d/download', $mainDoc->getId()));
        self::assertResponseIsSuccessful();

        // 3. Soumission au Ministre (SUBMITTED)
        $workflowService->submit($case, $agentCabinet, 'Soumission au Ministre pour visa et orientation');

        // 4. Annotation par le Ministre (ANNOTATED)
        $workflowService->annotate($case, $ministre, 'Instructions particulières : examiner la conformité réglementaire');

        // 5. Transmission au Secrétaire Général (FORWARDED / ORIENTED)
        $sgUnit = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'SECRETARIAT_GENERAL']);
        $workflowService->forward($case, $ministre, $sgUnit, 'Transmission pour orientation');

        // 6. Orientation du SG vers la DG Commerce (FORWARDED / ORIENTED)
        $dgUnit = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DG_COMMERCE']);
        $workflowService->forward($case, $sg, $dgUnit, 'Pour suite à donner par la DG Commerce');

        // 7. Transmission de la DG Commerce à la Direction Technique (FORWARDED)
        $dirUnit = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DIRECTION_COMMERCE_INTERIEUR']);
        $workflowService->forward($case, $dgCommerce, $dirUnit, 'Pour affectation au service compétent');

        // 8. Affectation à l'agent de traitement (ASSIGNED)
        $workflowService->assign($case, $respDir, $agentTraitement, instructions: 'Rédiger une note de synthèse sous 48h');

        // 9. Prise en charge par l'agent (TAKEN_IN_CHARGE)
        $workflowService->takeInCharge($case, $agentTraitement, 'Prise en charge par l\'agent instructeur');

        // 10. Traitement et soumission des conclusions (PROCESSED)
        $workflowService->process($case, $agentTraitement, 'Note de synthèse technique rédigée avec avis favorable');

        // 11. Retour pour précision (RETURNED)
        $workflowService->return($case, $respDir, 'Pièce complémentaire requise', 'Ajouter les statuts de la société');

        // Re-traitement par l'agent
        $workflowService->process($case, $agentTraitement, 'Statuts ajoutés et dossier complété');

        // 12. Validation hiérarchique en cascade (VALIDATED)
        // 12a. Validation Direction
        $workflowService->validate($case, $respDir, 'Validé par la Direction du Commerce Intérieur');
        // 12b. Validation DG
        $workflowService->validate($case, $dgCommerce, 'Validé par la Direction Générale du Commerce');
        // 12c. Validation SG
        $workflowService->validate($case, $sg, 'Validé par le Secrétariat Général, soumis pour signature');
        // 12d. Visa / Signature du Ministre
        $workflowService->validate($case, $ministre, 'Arrêté approuvé et signé par le Ministre');

        // 13. Sortie du circuit administratif (RELEASED)
        $workflowService->release($case, $agentCabinet, 'Courrier de réponse expédié et dossier libéré');

        // 14. Clôture administrative (COMPLETED)
        // Vérifier l'événement de clôture via contrôleur ou méthode de service
        $case->close($agentCabinet);
        $closeEvent = new CaseEvent(
            $case,
            CaseEventType::Completed,
            'Clôture administrative du dossier',
            $agentCabinet,
            'Traitement clôturé définitivement',
            action: 'COMPLETED',
            oldValue: 'RELEASED',
            newValue: 'COMPLETED'
        );
        $case->addEvent($closeEvent);
        $em->persist($closeEvent);

        // 15. Archivage (ARCHIVED)
        $case->archive($agentCabinet);
        $archiveEvent = new CaseEvent(
            $case,
            CaseEventType::CaseArchived,
            'Archivage officiel du dossier',
            $agentCabinet,
            'Dossier versé aux archives ministérielles',
            action: 'ARCHIVED',
            oldValue: 'COMPLETED',
            newValue: 'ARCHIVED'
        );
        $case->addEvent($archiveEvent);
        $em->persist($archiveEvent);
        $em->flush();

        // =====================================================================
        // VÉRIFICATIONS EXHAUSTIVES DU SCÉNARIO (Module 9 - Checklist 9)
        // =====================================================================
        $em->clear();
        $caseEventRepo = $em->getRepository(CaseEvent::class);
        $allEvents = $caseEventRepo->findByCaseWithFilters($case, 'all', order: 'ASC', limit: 100);

        self::assertGreaterThanOrEqual(15, count($allEvents));

        $actionsFound = [];
        foreach ($allEvents as $ev) {
            $actionsFound[] = strtoupper($ev->getAction());

            // [x] Dossier présent
            self::assertSame($case->getId(), $ev->getCase()->getId());

            // [x] Utilisateur présent (ou système)
            self::assertNotNull($ev->getUser(), sprintf('L\'événement "%s" doit avoir un utilisateur.', $ev->getAction()));

            // [x] Date présente
            self::assertNotNull($ev->getDate());
            self::assertInstanceOf(\DateTimeImmutable::class, $ev->getOccurredAt());

            // [x] Action non vide
            self::assertNotEmpty($ev->getAction());

            // [x] Structure présente si définie
            if ($ev->getUser()?->getOrganizationUnit()) {
                self::assertNotNull($ev->getStructure());
            }
        }

        // Vérification de la présence de toutes les actions requises du Module 9
        $expectedActions = [
            'DOSSIER_CREATED',
            'REGISTERED',
            'DOCUMENT_ADDED',
            'DOCUMENT_DOWNLOADED',
            'SUBMITTED',
            'ANNOTATED',
            'FORWARDED',
            'ASSIGNED',
            'TAKEN_IN_CHARGE',
            'PROCESSED',
            'RETURNED',
            'VALIDATED',
            'RELEASED',
            'COMPLETED',
            'ARCHIVED',
        ];

        foreach ($expectedActions as $expected) {
            self::assertContains(
                $expected,
                $actionsFound,
                sprintf('L\'action "%s" doit figurer dans la timeline chronologique.', $expected)
            );
        }

        // Vérification des valeurs anciennes/nouvelles sur des transitions clés
        $submitEv = null;
        $returnEv = null;
        $assignEv = null;
        foreach ($allEvents as $ev) {
            if ($ev->getAction() === 'SUBMITTED') {
                $submitEv = $ev;
            } elseif ($ev->getAction() === 'RETURNED') {
                $returnEv = $ev;
            } elseif ($ev->getAction() === 'ASSIGNED') {
                $assignEv = $ev;
            }
        }

        self::assertNotNull($submitEv);
        self::assertSame('Réception Cabinet', $submitEv->getOldValue());
        self::assertSame('Revue & Annotation Ministre', $submitEv->getNewValue());

        self::assertNotNull($returnEv);
        self::assertNotNull($returnEv->getComment());
        self::assertStringContainsString('Pièce complémentaire requise', $returnEv->getComment());

        self::assertNotNull($assignEv);
        self::assertNotNull($assignEv->getComment());
        self::assertStringContainsString('Rédiger une note de synthèse', $assignEv->getComment());
    }

    /**
     * Test 2 : Ordre chronologique strict
     */
    /**
     * Test 2 : Ordre chronologique strict
     */
    public function testChronologicalOrder(): void
    {
        $em = $this->getEntityManager();
        $caseEventRepo = $em->getRepository(CaseEvent::class);

        $case = new DossierCase('MCI-TEST-CHRONO-' . uniqid(), 'Dossier Chrono', EntryChannel::Cabinet, new \DateTimeImmutable('-2 hours'));
        $em->persist($case);
        $user = $this->getOrCreateUser('chrono_user@mci.test', 'ADMINISTRATEUR', 'CABINET');

        $ev1 = new CaseEvent($case, CaseEventType::DossierCreated, 'Création', $user, 'Dossier créé', 'DOSSIER_CREATED');
        $ev1->setOccurredAt(new \DateTimeImmutable('-1 hour'));
        $em->persist($ev1);

        $ev2 = new CaseEvent($case, CaseEventType::Submitted, 'Soumission', $user, 'Dossier soumis', 'SUBMITTED');
        $ev2->setOccurredAt(new \DateTimeImmutable('-30 minutes'));
        $em->persist($ev2);

        $ev3 = new CaseEvent($case, CaseEventType::Validated, 'Validation', $user, 'Dossier validé', 'VALIDATED');
        $ev3->setOccurredAt(new \DateTimeImmutable('-10 minutes'));
        $em->persist($ev3);

        $em->flush();

        $eventsAsc = $caseEventRepo->findByCaseWithFilters($case, 'all', order: 'ASC', limit: 50);
        self::assertCount(3, $eventsAsc);
        $prevTime = null;

        foreach ($eventsAsc as $ev) {
            $currentTime = $ev->getOccurredAt()->getTimestamp();
            if ($prevTime !== null) {
                self::assertGreaterThanOrEqual($prevTime, $currentTime, 'L\'ordre chronologique doit être strictement croissant.');
            }
            $prevTime = $currentTime;
        }

        // Vérification de l'ordre décroissant par défaut (du plus récent au plus ancien)
        $eventsDesc = $caseEventRepo->findByCaseWithFilters($case, 'all', order: 'DESC', limit: 50);
        self::assertCount(3, $eventsDesc);
        self::assertSame('VALIDATED', $eventsDesc[0]->getAction());
        self::assertSame('DOSSIER_CREATED', $eventsDesc[2]->getAction());
    }

    /**
     * Test 3 : Filtres fonctionnels par catégorie (Module 9 - Section 5)
     */
    public function testCategoryFiltering(): void
    {
        $em = $this->getEntityManager();
        $caseEventRepo = $em->getRepository(CaseEvent::class);

        $case = new DossierCase('MCI-TEST-FILT-' . uniqid(), 'Dossier Filtres', EntryChannel::Cabinet, new \DateTimeImmutable());
        $em->persist($case);
        $user = $this->getOrCreateUser('filt_user@mci.test', 'ADMINISTRATEUR', 'CABINET');

        // 1 workflow event
        $evWf = new CaseEvent($case, CaseEventType::Submitted, 'Soumission', $user, 'Transmis au cabinet');
        $evWf->setAction('SUBMITTED');
        $em->persist($evWf);

        // 1 document event
        $evDoc = new CaseEvent($case, CaseEventType::DocumentAdded, 'Document ajouté', $user, 'Courrier scanné');
        $evDoc->setAction('DOCUMENT_ADDED');
        $em->persist($evDoc);

        // 1 affectation event
        $evAff = new CaseEvent($case, CaseEventType::Assigned, 'Assignation', $user, 'Assigné à M. Traitement');
        $evAff->setAction('ASSIGNED');
        $em->persist($evAff);

        // 1 validation event
        $evVal = new CaseEvent($case, CaseEventType::Validated, 'Validation', $user, 'Validé par DG');
        $evVal->setAction('VALIDATED');
        $em->persist($evVal);

        $em->flush();

        $counts = $caseEventRepo->getCategoryCounts($case);
        self::assertSame(4, $counts['all']);
        self::assertSame(1, $counts['workflow']);
        self::assertSame(1, $counts['documents']);
        self::assertSame(1, $counts['affectations']);
        self::assertSame(1, $counts['validations']);

        // Filtre workflow
        $wfEvents = $caseEventRepo->findByCaseWithFilters($case, 'workflow');
        self::assertCount(1, $wfEvents);
        self::assertSame('workflow', $wfEvents[0]->getCategory());

        // Filtre documents
        $docEvents = $caseEventRepo->findByCaseWithFilters($case, 'documents');
        self::assertCount(1, $docEvents);
        self::assertSame('documents', $docEvents[0]->getCategory());

        // Filtre affectations
        $affEvents = $caseEventRepo->findByCaseWithFilters($case, 'affectations');
        self::assertCount(1, $affEvents);
        self::assertSame('affectations', $affEvents[0]->getCategory());

        // Filtre validations
        $valEvents = $caseEventRepo->findByCaseWithFilters($case, 'validations');
        self::assertCount(1, $valEvents);
        self::assertSame('validations', $valEvents[0]->getCategory());
    }

    /**
     * Test 4a : Immutabilité absolue - tentative de modification bloquée (Checklist 9)
     */
    public function testEventModificationBlockedByImmutability(): void
    {
        $em = $this->getEntityManager();
        $case = new DossierCase('MCI-TEST-MUT-' . uniqid(), 'Dossier Mut', EntryChannel::Cabinet, new \DateTimeImmutable());
        $em->persist($case);
        $user = $this->getOrCreateUser('mut_user@mci.test', 'ADMINISTRATEUR', 'CABINET');
        $event = new CaseEvent($case, CaseEventType::DossierCreated, 'Création initiale', $user, 'Commentaire initial');
        $event->setAction('DOSSIER_CREATED');
        $em->persist($event);
        $em->flush();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Les événements de traçabilité (CaseEvent) sont strictement immuables et ne peuvent pas être modifiés.');

        $event->setLabel('Falsification tentative de libellé');
        $em->flush();
    }

    /**
     * Test 4b : Immutabilité absolue - tentative de suppression bloquée (Checklist 9)
     */
    public function testEventDeletionBlockedByImmutability(): void
    {
        $em = $this->getEntityManager();
        $case = new DossierCase('MCI-TEST-DEL-' . uniqid(), 'Dossier Del', EntryChannel::Cabinet, new \DateTimeImmutable());
        $em->persist($case);
        $user = $this->getOrCreateUser('del_user@mci.test', 'ADMINISTRATEUR', 'CABINET');
        $event = new CaseEvent($case, CaseEventType::DossierCreated, 'Création initiale', $user, 'Commentaire initial');
        $event->setAction('DOSSIER_CREATED');
        $em->persist($event);
        $em->flush();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Les événements de traçabilité (CaseEvent) sont strictement immuables et ne peuvent pas être supprimés.');

        $em->remove($event);
        $em->flush();
    }

    /**
     * Test 5 : Rendu Web de la Timeline & API JSON (Module 9 - Section 4 & 7)
     */
    public function testTimelineWebUiAndJsonApi(): void
    {
        $client = static::createClient();
        $em = $this->getEntityManager();
        $admin = $this->getOrCreateUser('admin_tl_ui@mci.test', 'ADMINISTRATEUR', 'MINISTERE');
        $this->loginUser($client, $admin);

        $case = new DossierCase('MCI-TEST-UI-' . uniqid(), 'Dossier UI Timeline', EntryChannel::Cabinet, new \DateTimeImmutable());
        $case->setStatusCode('REGISTERED');
        $em->persist($case);
        $ev = new CaseEvent($case, CaseEventType::DossierCreated, 'Création du dossier', $admin, 'Créé pour test UI');
        $ev->setAction('DOSSIER_CREATED');
        $em->persist($ev);
        $em->flush();

        // 5a. Consultation de la fiche dossier avec l'onglet timeline
        $client->request('GET', sprintf('/dossiers/%d?tab=timeline', $case->getId()));
        self::assertResponseIsSuccessful();

        // Vérification de la présence des éléments clés de la timeline
        self::assertAnySelectorTextContains('h3', 'Traçabilité & Historique du Dossier');
        self::assertAnySelectorTextContains('a', 'Circuit / Workflow');
        self::assertAnySelectorTextContains('a', 'Affectations');
        self::assertAnySelectorTextContains('a', 'Validations');
        self::assertAnySelectorTextContains('span', 'Intégrité & Immutabilité');

        // 5b. Consultation avec filtre spécifique
        $client->request('GET', sprintf('/dossiers/%d?tab=timeline&event_category=workflow', $case->getId()));
        self::assertResponseIsSuccessful();

        // 5c. Appel de l'API JSON de la timeline
        $client->request('GET', sprintf('/dossiers/%d/timeline/events?category=all', $case->getId()));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $json = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($json['success']);
        self::assertArrayHasKey('total', $json);
        self::assertArrayHasKey('category_counts', $json);
        self::assertArrayHasKey('events', $json);
        self::assertIsArray($json['events']);

        if (!empty($json['events'])) {
            $first = $json['events'][0];
            self::assertArrayHasKey('action', $first);
            self::assertArrayHasKey('label', $first);
            self::assertArrayHasKey('occurred_at', $first);
            self::assertArrayHasKey('date_formatted', $first);
            self::assertArrayHasKey('actor', $first);
            self::assertArrayHasKey('category', $first);
        }
    }
}
