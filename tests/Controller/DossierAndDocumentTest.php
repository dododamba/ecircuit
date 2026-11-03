<?php

namespace App\Tests\Controller;

use App\Entity\AccessRole;
use App\Entity\CaseEvent;
use App\Entity\CaseTransmission;
use App\Entity\Document;
use App\Entity\DossierCase;
use App\Entity\OrganizationUnit;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\CaseEventType;
use App\Enum\EntryChannel;
use App\Enum\StructureType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DossierAndDocumentTest extends WebTestCase
{
    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function getPasswordHasher(): UserPasswordHasherInterface
    {
        return self::getContainer()->get(UserPasswordHasherInterface::class);
    }

    private function loginAgentWithRole(KernelBrowser $client, string $roleCode, string $email): User
    {
        $em = $this->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $user = new User($email, 'Agent', $roleCode);
            $user->setPassword($this->getPasswordHasher()->hashPassword($user, 'PasswordSecret123!'));
            $user->setStatus(AccountStatus::Active);

            $role = $em->getRepository(AccessRole::class)->findOneBy(['code' => $roleCode]);
            if ($role) {
                $user->addAccessRole($role);
            }

            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);
        return $user;
    }

    private function createDummyCase(string $refSuffix = ''): DossierCase
    {
        $em = $this->getEntityManager();
        $year = (int) date('Y');
        $unique = mt_rand(100000, 999999);
        $ref = sprintf('MCI-%d-%06d%s', $year, $unique, $refSuffix);

        $case = new DossierCase(
            $ref,
            'Dossier de test ' . $ref,
            EntryChannel::Cabinet,
            new \DateTimeImmutable()
        );
        $case->setStatusCode('REGISTERED');
        $case->setPriorityCode('NORMALE');

        $em->persist($case);
        $em->flush();

        return $case;
    }

    /**
     * 1. Test du contrôle d'accès et du filtrage sur la liste des dossiers
     */
    public function testDossierListAccessAndFiltering(): void
    {
        $client = static::createClient();

        // 1.1 Anonyme -> Redirection vers login
        $client->request('GET', '/dossiers');
        self::assertResponseRedirects('/login');

        // 1.2 Utilisateur sans droit DOSSIER_VIEW -> 403 Forbidden
        $em = $this->getEntityManager();
        $noPermUser = new User('noperm_case_' . bin2hex(random_bytes(4)) . '@mci.local', 'No', 'CasePerm');
        $noPermUser->setPassword($this->getPasswordHasher()->hashPassword($noPermUser, 'Pass123!'));
        $noPermUser->setStatus(AccountStatus::Active);
        $em->persist($noPermUser);
        $em->flush();

        $client->loginUser($noPermUser);
        $client->request('GET', '/dossiers');
        self::assertResponseStatusCodeSame(403);

        // 1.3 Utilisateur avec rôle Administrateur -> 200 OK
        $admin = $this->loginAgentWithRole($client, 'ADMINISTRATEUR', 'admin_cases@mci.gov');
        $crawler = $client->request('GET', '/dossiers');
        self::assertResponseIsSuccessful();
        self::assertAnySelectorTextContains('h2', 'Registre Général des Dossiers Administratifs');

        // 1.4 Test du formulaire de filtres
        $case = $this->createDummyCase('_FILTER');
        $crawlerFiltered = $client->request('GET', '/dossiers', ['search' => $case->getReference()]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $case->getReference());
    }

    /**
     * 2. Test de la vue détaillée d'un dossier avec les 5 onglets Metronic
     */
    public function testDossierShow5Tabs(): void
    {
        $client = static::createClient();
        $this->loginAgentWithRole($client, 'ADMINISTRATEUR', 'admin_tabs@mci.gov');

        $case = $this->createDummyCase('_TABS');

        // 2.1 Onglet Vue d'ensemble (défaut)
        $client->request('GET', sprintf('/dossiers/%d', $case->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', $case->getSubject());
        self::assertSelectorTextContains('body', 'Objet & Description du Dossier');

        // 2.2 Onglet Courriers liés
        $client->request('GET', sprintf('/dossiers/%d?tab=correspondences', $case->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Courriers Rattachés au Dossier');

        // 2.3 Onglet Documents & Pièces jointes
        $client->request('GET', sprintf('/dossiers/%d?tab=documents', $case->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Pièces Jointes & Documents du Dossier');
        self::assertSelectorTextContains('body', 'Ajouter un Document');

        // 2.4 Onglet Chronologie
        $client->request('GET', sprintf('/dossiers/%d?tab=timeline', $case->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Journal Chronologique des Actions & Événements');

        // 2.5 Onglet Transmissions
        $client->request('GET', sprintf('/dossiers/%d?tab=transmissions', $case->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Historique des Transmissions Inter-Services');
    }

    /**
     * 3. Test de l'affectation d'un dossier à un agent et/ou structure
     */
    public function testDossierAssignation(): void
    {
        $client = static::createClient();
        $admin = $this->loginAgentWithRole($client, 'ADMINISTRATEUR', 'admin_assign@mci.gov');
        $em = $this->getEntityManager();

        $case = $this->createDummyCase('_ASSIGN');
        $targetUser = $em->getRepository(User::class)->findOneBy(['email' => 'directeur.commerce@mci.gov']);
        if (!$targetUser) {
            $targetUser = new User('directeur.commerce@mci.gov', 'Directeur', 'Commerce');
            $targetUser->setPassword($this->getPasswordHasher()->hashPassword($targetUser, 'Pass123!'));
            $em->persist($targetUser);
            $em->flush();
        }

        // Récupérer le token CSRF depuis la page
        $crawler = $client->request('GET', sprintf('/dossiers/%d', $case->getId()));
        $token = $crawler->filter('form[action$="/assign"] input[name="_token"]')->attr('value');

        // Soumettre l'affectation
        $client->request('POST', sprintf('/dossiers/%d/assign', $case->getId()), [
            '_token' => $token,
            'assignee_id' => $targetUser->getId(),
            'instructions' => 'Veuillez instruire ce dossier sous 48 heures.',
        ]);

        self::assertResponseRedirects(sprintf('/dossiers/%d?tab=timeline', $case->getId()));
        $client->followRedirect();

        // Vérification en base de données
        $em->clear();
        $updatedCase = $em->getRepository(DossierCase::class)->find($case->getId());
        self::assertNotNull($updatedCase);
        self::assertNotNull($updatedCase->getCurrentAssignee());
        self::assertSame($targetUser->getId(), $updatedCase->getCurrentAssignee()->getId());
        self::assertSame('IN_ANALYSIS', $updatedCase->getStatusCode());

        // Vérifier l'événement CaseEvent
        $events = $updatedCase->getEvents();
        self::assertGreaterThanOrEqual(1, $events->count());
        $assignEvent = null;
        foreach ($events as $event) {
            if ($event->getEventType() === CaseEventType::CaseAssigned) {
                $assignEvent = $event;
                break;
            }
        }
        self::assertNotNull($assignEvent);
        self::assertStringContainsString('Directeur Commerce', $assignEvent->getLabel());
        self::assertSame('Veuillez instruire ce dossier sous 48 heures.', $assignEvent->getDescription());
    }

    /**
     * 4. Test de la transmission inter-services d'un dossier
     */
    public function testDossierTransmission(): void
    {
        $client = static::createClient();
        $this->loginAgentWithRole($client, 'ADMINISTRATEUR', 'admin_trans@mci.gov');
        $em = $this->getEntityManager();

        $case = $this->createDummyCase('_TRANS');

        // Récupérer 2 structures
        $units = $em->getRepository(OrganizationUnit::class)->findAll();
        if (count($units) < 2) {
            $u1 = new OrganizationUnit('U1_TEST', 'Cabinet Test', StructureType::Cabinet);
            $u2 = new OrganizationUnit('U2_TEST', 'Direction Test', StructureType::Direction);
            $em->persist($u1);
            $em->persist($u2);
            $em->flush();
            $units = [$u1, $u2];
        }

        $sourceUnit = $units[0];
        $targetUnit = $units[1];
        $case->setCurrentStructure($sourceUnit);
        $em->flush();

        $crawler = $client->request('GET', sprintf('/dossiers/%d?tab=transmissions', $case->getId()));
        $token = $crawler->filter('form[action$="/transmit"] input[name="_token"]')->first()->attr('value');

        $client->request('POST', sprintf('/dossiers/%d/transmit', $case->getId()), [
            '_token' => $token,
            'target_unit_id' => $targetUnit->getId(),
            'reason' => 'Pour avis technique',
            'instructions' => 'Merci d\'émettre un avis motivé sur la faisabilité technique.',
        ]);

        self::assertResponseRedirects(sprintf('/dossiers/%d?tab=transmissions', $case->getId()));
        $client->followRedirect();

        // Vérification en base de données
        $em->clear();
        $updatedCase = $em->getRepository(DossierCase::class)->find($case->getId());
        self::assertNotNull($updatedCase);
        self::assertSame($targetUnit->getId(), $updatedCase->getCurrentStructure()->getId());
        self::assertNull($updatedCase->getCurrentAssignee()); // Assignataire réinitialisé
        self::assertSame('TRANSMITTED', $updatedCase->getStatusCode());

        // Vérification de CaseTransmission
        self::assertCount(1, $updatedCase->getTransmissions());
        $trans = $updatedCase->getTransmissions()->first();
        self::assertSame($targetUnit->getId(), $trans->getTargetUnit()->getId());
        self::assertSame('Pour avis technique', $trans->getReason());
        self::assertSame('Merci d\'émettre un avis motivé sur la faisabilité technique.', $trans->getInstructions());
    }

    /**
     * 5. Test de l'ajout d'une note interne (Module 5)
     */
    public function testDossierAddNote(): void
    {
        $client = static::createClient();
        $this->loginAgentWithRole($client, 'ADMINISTRATEUR', 'admin_note@mci.gov');
        $em = $this->getEntityManager();

        $case = $this->createDummyCase('_NOTE');

        $crawler = $client->request('GET', sprintf('/dossiers/%d?tab=timeline', $case->getId()));
        $token = $crawler->filter('form[action$="/note"] input[name="_token"]')->attr('value');

        $noteText = 'Réunion de cadrage tenue ce matin avec la délégation des industriels.';
        $client->request('POST', sprintf('/dossiers/%d/note', $case->getId()), [
            '_token' => $token,
            'note' => $noteText,
        ]);

        self::assertResponseRedirects(sprintf('/dossiers/%d?tab=timeline', $case->getId()));
        $client->followRedirect();

        // Vérification de l'événement CaseEvent NoteAdded
        $em->clear();
        $updatedCase = $em->getRepository(DossierCase::class)->find($case->getId());
        self::assertNotNull($updatedCase);

        $noteEvent = null;
        foreach ($updatedCase->getEvents() as $ev) {
            if ($ev->getEventType() === CaseEventType::NoteAdded) {
                $noteEvent = $ev;
                break;
            }
        }
        self::assertNotNull($noteEvent);
        self::assertSame($noteText, $noteEvent->getDescription());
    }

    /**
     * 6. Test du téléversement et du téléchargement de document complémentaire (Module 6)
     */
    public function testDossierDocumentUploadAndDownload(): void
    {
        $client = static::createClient();
        $this->loginAgentWithRole($client, 'ADMINISTRATEUR', 'admin_doc@mci.gov');
        $em = $this->getEntityManager();

        $case = $this->createDummyCase('_DOC');

        // Création d'un faux fichier PDF temporaire
        $tmpFilePath = tempnam(sys_get_temp_dir(), 'test_doc_') . '.pdf';
        file_put_contents($tmpFilePath, '%PDF-1.4 Fake test PDF content for MCI e-Circuit Document Module 6');

        $uploadedFile = new UploadedFile(
            $tmpFilePath,
            'rapport_technique_annexe.pdf',
            'application/pdf',
            null,
            true
        );

        $crawler = $client->request('GET', sprintf('/dossiers/%d?tab=documents', $case->getId()));
        $token = $crawler->filter('form[action$="/documents/upload"] input[name="_token"]')->attr('value');

        $client->request(
            'POST',
            sprintf('/dossiers/%d/documents/upload', $case->getId()),
            [
                '_token' => $token,
                'document_type' => 'NOTE',
            ],
            [
                'document_file' => $uploadedFile,
            ]
        );

        self::assertResponseRedirects(sprintf('/dossiers/%d?tab=documents', $case->getId()));
        $client->followRedirect();

        // Vérification de l'entité Document
        $em->clear();
        $updatedCase = $em->getRepository(DossierCase::class)->find($case->getId());
        self::assertNotNull($updatedCase);
        self::assertCount(1, $updatedCase->getDocuments());

        $doc = $updatedCase->getDocuments()->first();
        self::assertSame('rapport_technique_annexe.pdf', $doc->getOriginalName());
        self::assertSame('NOTE', $doc->getType());
        self::assertSame('application/pdf', $doc->getMimeType());

        // Test du téléchargement sécurisé du document
        $client->request('GET', sprintf('/document/%d/download', $doc->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('rapport_technique_annexe.pdf', (string) $client->getResponse()->headers->get('Content-Disposition'));
    }

    /**
     * 7. Test de la clôture et de l'archivage du dossier (Module 5)
     */
    public function testDossierCloseAndArchive(): void
    {
        $client = static::createClient();
        $this->loginAgentWithRole($client, 'ADMINISTRATEUR', 'admin_close@mci.gov');
        $em = $this->getEntityManager();

        $case = $this->createDummyCase('_CLOSE');

        // 7.1 Clôture
        $crawler = $client->request('GET', sprintf('/dossiers/%d', $case->getId()));
        $tokenClose = $crawler->filter('form[action$="/close"] input[name="_token"]')->attr('value');

        $client->request('POST', sprintf('/dossiers/%d/close', $case->getId()), [
            '_token' => $tokenClose,
        ]);

        self::assertResponseRedirects(sprintf('/dossiers/%d', $case->getId()));
        $client->followRedirect();

        $em->clear();
        $closedCase = $em->getRepository(DossierCase::class)->find($case->getId());
        self::assertNotNull($closedCase);
        self::assertTrue($closedCase->isClosed());
        self::assertSame('COMPLETED', $closedCase->getStatusCode());
        self::assertNotNull($closedCase->getClosedAt());
        self::assertNotNull($closedCase->getClosedBy());

        // 7.2 Archivage
        $crawlerArch = $client->request('GET', sprintf('/dossiers/%d', $case->getId()));
        $tokenArch = $crawlerArch->filter('form[action$="/archive"] input[name="_token"]')->attr('value');

        $client->request('POST', sprintf('/dossiers/%d/archive', $case->getId()), [
            '_token' => $tokenArch,
        ]);

        self::assertResponseRedirects(sprintf('/dossiers/%d', $case->getId()));
        $client->followRedirect();

        $em->clear();
        $archivedCase = $em->getRepository(DossierCase::class)->find($case->getId());
        self::assertNotNull($archivedCase);
        self::assertTrue($archivedCase->isArchived());
        self::assertSame('ARCHIVED', $archivedCase->getStatusCode());
        self::assertNotNull($archivedCase->getArchivedAt());
        self::assertNotNull($archivedCase->getArchivedBy());
    }

    /**
     * 8. Test S1.08 / S5.04 — Cloisonnement inter-structures strict (Dossier, Document, Recherche, Audit ACCESS_DENIED)
     */
    public function testInterStructureIsolationAndAuditLogging(): void
    {
        $client = static::createClient();
        $em = $this->getEntityManager();

        $dci = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DIRECTION_COMMERCE_INTERIEUR']);
        if (!$dci) {
            $dci = new OrganizationUnit('DIRECTION_COMMERCE_INTERIEUR', 'Direction du Commerce Intérieur', StructureType::Direction);
            $em->persist($dci);
        }

        $ddi = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DIRECTION_DEVELOPPEMENT_INDUSTRIEL']);
        if (!$ddi) {
            $ddi = new OrganizationUnit('DIRECTION_DEVELOPPEMENT_INDUSTRIEL', 'Direction du Développement Industriel', StructureType::Direction);
            $em->persist($ddi);
        }

        $agentRole = $em->getRepository(AccessRole::class)->findOneBy(['code' => 'AGENT_TRAITEMENT']);
        if (!$agentRole) {
            $agentRole = new AccessRole('AGENT_TRAITEMENT', 'Agent de traitement');
            foreach (['DOSSIER_VIEW', 'DOCUMENT_VIEW', 'DOCUMENT_DOWNLOAD', 'SEARCH_USE'] as $permCode) {
                $perm = $em->getRepository(\App\Entity\AccessPermission::class)->findOneBy(['code' => $permCode]);
                if ($perm) {
                    $agentRole->grant($perm);
                }
            }
            $em->persist($agentRole);
        }

        // Agent affecté à la Direction du Commerce Intérieur (DCI)
        $agentDciEmail = 'agent.dci.isolation.' . bin2hex(random_bytes(3)) . '@mci.gov';
        $agentDci = new User($agentDciEmail, 'Agent', 'DCI');
        $agentDci->setPassword($this->getPasswordHasher()->hashPassword($agentDci, 'Pass123!'));
        $agentDci->setStatus(AccountStatus::Active);
        $agentDci->setOrganizationUnit($dci);
        $agentDci->addAccessRole($agentRole);
        $em->persist($agentDci);

        // Agent affecté à la Direction du Développement Industriel (DDI)
        $agentDdiEmail = 'agent.ddi.isolation.' . bin2hex(random_bytes(3)) . '@mci.gov';
        $agentDdi = new User($agentDdiEmail, 'Agent', 'DDI');
        $agentDdi->setPassword($this->getPasswordHasher()->hashPassword($agentDdi, 'Pass123!'));
        $agentDdi->setStatus(AccountStatus::Active);
        $agentDdi->setOrganizationUnit($ddi);
        $agentDdi->addAccessRole($agentRole);
        $em->persist($agentDdi);

        // Dossier DCI
        $caseDci = $this->createDummyCase('_DCI');
        $caseDci->setCurrentStructure($dci);

        // Dossier DDI + Document rattaché
        $caseDdi = $this->createDummyCase('_DDI');
        $caseDdi->setCurrentStructure($ddi);

        $storageDir = self::getContainer()->getParameter('kernel.project_dir') . '/var/uploads/documents';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0775, true);
        }
        $storageFile = 'ddi_confidentiel_' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($storageDir . '/' . $storageFile, '%PDF-1.4 Document Confidentiel DDI');

        $docDdi = new Document(
            'note_industrielle_ddi.pdf',
            $storageFile,
            'application/pdf',
            36,
            'var/uploads/documents/' . $storageFile,
            'NOTE',
            $agentDdi
        );
        $docDdi->setCase($caseDdi);
        $em->persist($docDdi);
        $em->flush();

        // 8.1 Connexion en tant qu'Agent DCI
        $client->loginUser($agentDci);

        // Accès autorisé à son propre dossier DCI
        $client->request('GET', sprintf('/dossiers/%d', $caseDci->getId()));
        self::assertResponseIsSuccessful();

        // Accès refusé (403) au dossier DDI d'une autre direction
        $client->request('GET', sprintf('/dossiers/%d', $caseDdi->getId()));
        self::assertResponseStatusCodeSame(403);

        // Accès refusé (403) au téléchargement du document du dossier DDI
        $client->request('GET', sprintf('/document/%d/download', $docDdi->getId()));
        self::assertResponseStatusCodeSame(403);

        // Liste des dossiers : voit DCI mais ne voit pas DDI
        $client->request('GET', '/dossiers');
        self::assertResponseIsSuccessful();
        $listContent = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($caseDci->getReference(), $listContent);
        self::assertStringNotContainsString($caseDdi->getReference(), $listContent);

        // Recherche : voit DCI mais ne voit pas DDI
        $client->request('GET', '/recherche', ['q' => 'Dossier de test']);
        self::assertResponseIsSuccessful();
        $searchContent = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($caseDci->getReference(), $searchContent);
        self::assertStringNotContainsString($caseDdi->getReference(), $searchContent);

        // Vérification de la journalisation d'audit ACCESS_DENIED
        $deniedLogs = $em->getRepository(\App\Entity\AuditLog::class)->findBy([
            'user' => $agentDci,
            'result' => 'DENIED',
        ]);
        self::assertNotEmpty($deniedLogs, 'Un refus d\'accès inter-structures doit être journalisé dans AuditLog.');

        // 8.2 Connexion en tant qu'Agent DDI -> accès autorisé au dossier et au document DDI
        $client->loginUser($agentDdi);
        $client->request('GET', sprintf('/dossiers/%d', $caseDdi->getId()));
        self::assertResponseIsSuccessful();

        $client->request('GET', sprintf('/document/%d/download', $docDdi->getId()));
        self::assertResponseIsSuccessful();
    }

    public function testDossierDeadlineUpdateAndTimelineEvent(): void
    {
        $client = static::createClient();
        $this->loginAgentWithRole($client, 'ADMINISTRATEUR', 'admin_deadline@mci.gov');
        $em = $this->getEntityManager();

        $case = $this->createDummyCase('_DEADLINE');
        $crawler = $client->request('GET', sprintf('/dossiers/%d', $case->getId()));
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/deadline"]');
        self::assertGreaterThanOrEqual(1, $form->count(), 'Le formulaire de modification d\'échéance doit être présent.');
        $token = $form->filter('input[name="_token"]')->attr('value');

        $targetDate = (new \DateTimeImmutable('+15 days'))->format('Y-m-d');
        $client->request('POST', sprintf('/dossiers/%d/deadline', $case->getId()), [
            '_token' => $token,
            'deadline' => $targetDate,
            'justification' => 'Prolongation administrative suite à demande de compléments.',
        ]);

        self::assertResponseRedirects(sprintf('/dossiers/%d?tab=timeline', $case->getId()));
        $client->followRedirect();

        $em->clear();
        $reloaded = $em->getRepository(DossierCase::class)->find($case->getId());
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getDeadline());
        self::assertSame($targetDate, $reloaded->getDeadline()->format('Y-m-d'));

        // Vérifier l'événement de timeline CaseEvent (Module 9)
        $events = $reloaded->getEvents();
        $deadlineEvent = null;
        foreach ($events as $event) {
            if ($event->getAction() === 'DEADLINE_UPDATED') {
                $deadlineEvent = $event;
                break;
            }
        }
        self::assertNotNull($deadlineEvent, 'Un événement DEADLINE_UPDATED doit être enregistré dans la timeline');
        self::assertSame('Prolongation administrative suite à demande de compléments.', $deadlineEvent->getDescription());
        self::assertSame($targetDate, $deadlineEvent->getNewValue());
    }
}
