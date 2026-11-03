<?php

namespace App\Tests\Controller;

use App\Entity\AccessRole;
use App\Entity\Correspondence;
use App\Entity\DossierCase;
use App\Entity\ReferenceItem;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\CaseEventType;
use App\Enum\CorrespondenceStatus;
use App\Enum\EntryChannel;
use App\Enum\ReferenceCategory;
use App\Enum\SenderType;
use App\Service\ReferenceNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CourrierAndNumberingTest extends WebTestCase
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

    /**
     * 1. Test du générateur de numérotation séquentielle MCI-YYYY-NNNNNN (Module 4)
     */
    public function testReferenceNumberGeneratorFormatAndSequence(): void
    {
        self::bootKernel();
        /** @var ReferenceNumberGenerator $generator */
        $generator = self::getContainer()->get(ReferenceNumberGenerator::class);

        $currentYear = (int) (new \DateTimeImmutable())->format('Y');

        $ref1 = $generator->generateNextReference();
        self::assertTrue(ReferenceNumberGenerator::isValidFormat($ref1));
        self::assertMatchesRegularExpression(sprintf('/^MCI-%d-\d{6}$/', $currentYear), $ref1);

        $ref2 = $generator->generateNextReference();
        self::assertTrue(ReferenceNumberGenerator::isValidFormat($ref2));

        // Extraction et comparaison de l'incrémentation
        $num1 = (int) substr($ref1, -6);
        $num2 = (int) substr($ref2, -6);
        self::assertSame($num1 + 1, $num2);

        // Validation syntaxique
        self::assertTrue(ReferenceNumberGenerator::isValidFormat('MCI-2026-000001'));
        self::assertFalse(ReferenceNumberGenerator::isValidFormat('MCI-26-0001'));
        self::assertFalse(ReferenceNumberGenerator::isValidFormat('DOC-2026-000001'));
        self::assertFalse(ReferenceNumberGenerator::isValidFormat('invalid'));
    }

    /**
     * 2. Test du contrôle d'accès au registre des courriers (Module 3)
     */
    public function testIncomingMailListAccess(): void
    {
        $client = static::createClient();

        // 2.1 Anonyme -> Redirection vers login
        $client->request('GET', '/courrier/entrant');
        self::assertResponseRedirects('/login');

        // 2.2 Utilisateur sans droit COURRIER_VIEW -> 403
        $em = $this->getEntityManager();
        $noPermUser = new User('noperm_' . bin2hex(random_bytes(4)) . '@mci.local', 'No', 'Perm');
        $noPermUser->setPassword($this->getPasswordHasher()->hashPassword($noPermUser, 'Pass123!'));
        $noPermUser->setStatus(AccountStatus::Active);
        $em->persist($noPermUser);
        $em->flush();

        $client->loginUser($noPermUser);
        $client->request('GET', '/courrier/entrant');
        self::assertResponseStatusCodeSame(403);

        // 2.3 Agent Service Courrier -> 200 et affichage du registre
        $this->loginAgentWithRole($client, 'SERVICE_COURRIER', 'agent.courrier@mci.local');
        $client->request('GET', '/courrier/entrant');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Registre des Courriers Entrants');
        self::assertSelectorExists('a[href="/courrier/entrant/nouveau"]');
    }

    /**
     * 3. Test de l'enregistrement complet d'un courrier entrant avec document et dossier automatique (Modules 3 & 4)
     */
    public function testIncomingMailRegistrationWithDocument(): void
    {
        $client = static::createClient();
        $user = $this->loginAgentWithRole($client, 'SERVICE_COURRIER', 'agent.courrier@mci.local');

        $crawler = $client->request('GET', '/courrier/entrant/nouveau');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Enregistrement Courrier Entrant');

        // Création d'un faux fichier PDF temporaire
        $tempPdfPath = sys_get_temp_dir() . '/test_mci_' . uniqid() . '.pdf';
        file_put_contents($tempPdfPath, '%PDF-1.4 Fake PDF Content for Automated Testing %%EOF');

        $uploadedFile = new UploadedFile(
            $tempPdfPath,
            'dossier_agrement_commercial.pdf',
            'application/pdf',
            null,
            true
        );

        $form = $crawler->selectButton('Enregistrer & Générer la Fiche Officielle')->form([
            'sender_type' => SenderType::Entreprise->value,
            'sender_name' => 'Société Ivoirienne de Négoce',
            'sender_organisation' => 'SIN SARL',
            'sender_phone' => '+225 07 12 34 56 78',
            'sender_email' => 'contact@sin-ci.com',
            'sender_address' => 'Abidjan Yopougon Zone Industrielle',
            'entry_channel' => EntryChannel::Cabinet->value,
            'subject' => 'Demande d’agrément pour l’importation et la commercialisation de riz',
            'sender_reference' => 'SIN/DIR/2026/042',
            'observations' => 'Dossier original remis sous pli cacheté.',
        ]);

        $form['main_document']->upload($uploadedFile);

        $client->submit($form);
        self::assertResponseRedirects();

        // Récupération de l'ID généré via l'URL de redirection
        $redirectUrl = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/courrier/\d+#', $redirectUrl);
        preg_match('#/courrier/(\d+)#', $redirectUrl, $matches);
        $newId = (int) $matches[1];

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="alert"]', 'a été enregistré avec succès');

        // Vérifications approfondies en base de données
        $em = $this->getEntityManager();
        $em->clear();

        /** @var Correspondence|null $correspondence */
        $correspondence = $em->getRepository(Correspondence::class)->find($newId);
        self::assertNotNull($correspondence);

        // Vérification de la référence officielle Module 4
        $ref = $correspondence->getReference();
        self::assertNotNull($ref);
        self::assertTrue(ReferenceNumberGenerator::isValidFormat($ref));

        // Vérification des données Courrier
        self::assertSame(CorrespondenceStatus::Validated, $correspondence->getStatus());
        self::assertSame(EntryChannel::Cabinet, $correspondence->getEntryChannel());
        self::assertNotNull($correspondence->getRegisteredAt());
        self::assertNotNull($correspondence->getSender());
        self::assertSame('Société Ivoirienne de Négoce', $correspondence->getSender()->getName());

        // Vérification du document principal téléversé
        self::assertNotNull($correspondence->getMainDocument());
        self::assertStringEndsWith('.pdf', $correspondence->getMainDocument()->getOriginalName());
        self::assertSame('application/pdf', $correspondence->getMainDocument()->getMimeType());

        // Vérification de la création automatique et atomique du Dossier (DossierCase)
        $case = $correspondence->getCase();
        self::assertNotNull($case);
        self::assertSame($ref, $case->getReference());
        self::assertSame('REGISTERED', $case->getStatusCode());
        self::assertSame('Demande d’agrément pour l’importation et la commercialisation de riz', $case->getSubject());
        self::assertSame($correspondence->getSender()->getId(), $case->getSender()->getId());

        // Le document doit aussi être rattaché au dossier
        self::assertCount(1, $case->getDocuments());

        // Vérification de l'événement CaseEvent
        self::assertGreaterThanOrEqual(1, $case->getEvents()->count());
        $event = $case->getEvents()->first();
        self::assertSame(CaseEventType::CaseRegistered, $event->getEventType());

        // Nettoyage fichier temporaire si existant
        if (file_exists($tempPdfPath)) {
            @unlink($tempPdfPath);
        }
    }

    /**
     * 4. Test de la fiche suiveuse imprimable (Module 3)
     */
    public function testRoutingSlipPrintableTemplate(): void
    {
        $client = static::createClient();
        $this->loginAgentWithRole($client, 'SERVICE_COURRIER', 'agent.courrier@mci.local');

        $em = $this->getEntityManager();
        /** @var Correspondence|null $correspondence */
        $correspondence = $em->getRepository(Correspondence::class)->findOneBy([], ['id' => 'DESC']);
        self::assertNotNull($correspondence);

        $client->request('GET', '/courrier/' . $correspondence->getId() . '/fiche-suiveuse');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Fiche Suiveuse');
        self::assertSelectorTextContains('.slip-title', 'FICHE SUIVEUSE DE COURRIER ENTRANT');
        self::assertSelectorTextContains('.ref-number', $correspondence->getReference());
        self::assertSelectorExists('.routing-table');
    }

    /**
     * 5. Test du récépissé officiel de dépôt pour l'usager (Module 3)
     */
    public function testReceiptPrintableTemplate(): void
    {
        $client = static::createClient();
        $this->loginAgentWithRole($client, 'SERVICE_COURRIER', 'agent.courrier@mci.local');

        $em = $this->getEntityManager();
        /** @var Correspondence|null $correspondence */
        $correspondence = $em->getRepository(Correspondence::class)->findOneBy([], ['id' => 'DESC']);
        self::assertNotNull($correspondence);

        $client->request('GET', '/courrier/' . $correspondence->getId() . '/recepisse');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.receipt-title', 'Récépissé Officiel de Dépôt de Courrier');
        self::assertSelectorTextContains('.ref-value', $correspondence->getReference());
    }

    /**
     * 6. Test du téléchargement sécurisé du document numérisé
     */
    public function testSecureDocumentDownload(): void
    {
        $client = static::createClient();
        $this->loginAgentWithRole($client, 'SERVICE_COURRIER', 'agent.courrier@mci.local');

        $em = $this->getEntityManager();
        /** @var Correspondence|null $correspondence */
        $correspondence = $em->getRepository(Correspondence::class)->findOneBy([], ['id' => 'DESC']);
        self::assertNotNull($correspondence);

        $doc = $correspondence->getMainDocument();
        self::assertNotNull($doc);

        $client->request('GET', '/document/' . $doc->getId() . '/download');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('pdf', strtolower((string) $client->getResponse()->headers->get('Content-Type')));
    }

    /**
     * 7. Test de filtrage et recherche dans le registre
     */
    public function testIncomingMailFilterSearch(): void
    {
        $client = static::createClient();
        $this->loginAgentWithRole($client, 'SERVICE_COURRIER', 'agent.courrier@mci.local');

        // Recherche par mot-clé correspondant
        $client->request('GET', '/courrier/entrant?search=Négoce');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Société Ivoirienne de Négoce');

        // Recherche par mot-clé inexistant
        $client->request('GET', '/courrier/entrant?search=TermeInexistant999');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('td', 'Aucun courrier entrant ne correspond');
    }

    /**
     * 8. Test S2.09 — Numérotation MCI-OUT-YYYY-NNNNNN et cycle complet du courrier sortant
     */
    public function testOutgoingMailLifecycleAndCaseAttachment(): void
    {
        $client = static::createClient();
        $this->loginAgentWithRole($client, 'SERVICE_COURRIER', 'agent.courrier@mci.local');
        $em = $this->getEntityManager();

        /** @var ReferenceNumberGenerator $generator */
        $generator = self::getContainer()->get(ReferenceNumberGenerator::class);
        $currentYear = (int) date('Y');
        $outRef1 = $generator->generateNextOutgoingReference();
        $outRef2 = $generator->generateNextOutgoingReference();

        self::assertTrue(ReferenceNumberGenerator::isValidOutgoingFormat($outRef1));
        self::assertTrue(ReferenceNumberGenerator::isValidOutgoingFormat($outRef2));
        self::assertMatchesRegularExpression(sprintf('/^MCI-OUT-%d-\d{6}$/', $currentYear), $outRef1);
        self::assertSame(((int) substr($outRef1, -6)) + 1, (int) substr($outRef2, -6));

        // Récupérer un dossier existant pour y rattacher le courrier sortant
        /** @var DossierCase|null $case */
        $case = $em->getRepository(DossierCase::class)->findOneBy([], ['id' => 'DESC']);
        self::assertNotNull($case);

        // Formulaire de création d'un courrier sortant lié au dossier
        $crawler = $client->request('GET', '/courrier/sortant/nouveau?case_id=' . $case->getId());
        self::assertResponseIsSuccessful();

        $tempPdfPath = sys_get_temp_dir() . '/test_mci_out_' . uniqid() . '.pdf';
        file_put_contents($tempPdfPath, '%PDF-1.4 Courrier Sortant Signé MCI %%EOF');
        $uploadedFile = new UploadedFile(
            $tempPdfPath,
            'notification_agrement_signee.pdf',
            'application/pdf',
            null,
            true
        );

        $form = $crawler->selectButton('Enregistrer le courrier sortant')->form([
            'case_id' => (string) $case->getId(),
            'subject' => 'Notification officielle d’octroi d’agrément commercial',
            'recipient_name' => 'Directeur Général SIN SARL',
            'recipient_organisation' => 'Société Ivoirienne de Négoce',
            'dispatch_method' => 'REMISE_OFFICIELLE',
            'status' => CorrespondenceStatus::Draft->value,
            'observations' => 'Ampliation : Secrétariat Général et DG Commerce.',
        ]);
        $form['main_document']->upload($uploadedFile);

        $client->submit($form);
        self::assertResponseRedirects();

        $redirectUrl = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/courrier/sortant/\d+#', $redirectUrl);
        preg_match('#/courrier/sortant/(\d+)#', $redirectUrl, $matches);
        $outgoingId = (int) $matches[1];

        $crawlerShow = $client->followRedirect();
        self::assertResponseIsSuccessful();

        $em->clear();
        /** @var Correspondence|null $outgoing */
        $outgoing = $em->getRepository(Correspondence::class)->find($outgoingId);
        self::assertNotNull($outgoing);
        self::assertTrue($outgoing->isOutgoing());
        self::assertTrue(ReferenceNumberGenerator::isValidOutgoingFormat((string) $outgoing->getReference()));
        self::assertSame(CorrespondenceStatus::Draft, $outgoing->getStatus());
        self::assertSame('Directeur Général SIN SARL', $outgoing->getRecipientName());
        self::assertSame($case->getId(), $outgoing->getCase()?->getId());
        self::assertNotNull($outgoing->getMainDocument());

        // Transition DRAFT -> VALIDATED
        $tokenStatus = $crawlerShow->filter('form[action$="/status"] input[name="_token"]')->attr('value');
        $client->request('POST', sprintf('/courrier/sortant/%d/status', $outgoingId), [
            '_token' => $tokenStatus,
            'target_status' => CorrespondenceStatus::Validated->value,
            'comment' => 'Validé et signé par le Secrétaire Général.',
        ]);
        self::assertResponseRedirects(sprintf('/courrier/sortant/%d', $outgoingId));
        $crawlerShow = $client->followRedirect();

        // Transition VALIDATED -> SENT
        $tokenStatus2 = $crawlerShow->filter('form[action$="/status"] input[name="_token"]')->attr('value');
        $client->request('POST', sprintf('/courrier/sortant/%d/status', $outgoingId), [
            '_token' => $tokenStatus2,
            'target_status' => CorrespondenceStatus::Sent->value,
            'comment' => 'Remis contre décharge n°2026/884.',
        ]);
        self::assertResponseRedirects(sprintf('/courrier/sortant/%d', $outgoingId));
        $client->followRedirect();

        $em->clear();
        $sentOutgoing = $em->getRepository(Correspondence::class)->find($outgoingId);
        self::assertNotNull($sentOutgoing);
        self::assertSame(CorrespondenceStatus::Sent, $sentOutgoing->getStatus());
        self::assertNotNull($sentOutgoing->getSentAt());

        // Vérification dans le registre sortant (/courrier/sortant)
        $client->request('GET', '/courrier/sortant');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $sentOutgoing->getReference());

        if (file_exists($tempPdfPath)) {
            @unlink($tempPdfPath);
        }
    }
}
