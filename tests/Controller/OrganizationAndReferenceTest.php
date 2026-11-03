<?php

namespace App\Tests\Controller;

use App\Entity\AccessRole;
use App\Entity\OrganizationUnit;
use App\Entity\ReferenceItem;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\ReferenceCategory;
use App\Enum\StructureType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class OrganizationAndReferenceTest extends WebTestCase
{
    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function getPasswordHasher(): UserPasswordHasherInterface
    {
        return self::getContainer()->get(UserPasswordHasherInterface::class);
    }

    private function loginAdmin(KernelBrowser $client): User
    {
        $em = $this->getEntityManager();
        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'admin@mci.local']);
        if (!$admin) {
            $role = $em->getRepository(AccessRole::class)->findOneBy(['code' => 'ADMINISTRATEUR']);
            $admin = new User('admin@mci.local', 'Super', 'Admin');
            $admin->setPassword($this->getPasswordHasher()->hashPassword($admin, 'TestAdminSecret123!'));
            $admin->setStatus(AccountStatus::Active);
            if ($role) {
                $admin->setRole($role);
            }
            $em->persist($admin);
            $em->flush();
        }

        $client->loginUser($admin);
        return $admin;
    }

    private function createAgentWithoutPermissions(string $prefix = 'no_perm'): User
    {
        $em = $this->getEntityManager();
        $email = $prefix . '_' . bin2hex(random_bytes(4)) . '@mci.local';

        $user = new User($email, 'Agent', 'Simple');
        $user->setPassword($this->getPasswordHasher()->hashPassword($user, 'Password123!'));
        $user->setStatus(AccountStatus::Active);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /**
     * 1. Test unitaire des méthodes de hiérarchie d'OrganizationUnit
     */
    public function testStructureHierarchyPathAndIsWithin(): void
    {
        $cabinet = new OrganizationUnit('CAB_UNIT_TEST', 'Cabinet Test', StructureType::Cabinet);
        $dir = new OrganizationUnit('DIR_UNIT_TEST', 'Direction Test', StructureType::Direction);
        $dir->setParent($cabinet);
        $service = new OrganizationUnit('SRV_UNIT_TEST', 'Service Test', StructureType::Service);
        $service->setParent($dir);

        self::assertSame('Cabinet Test > Direction Test > Service Test', $service->getHierarchyPath());
        self::assertSame('Cabinet Test > Direction Test', $dir->getHierarchyPath());
        self::assertSame('Cabinet Test', $cabinet->getHierarchyPath());

        self::assertTrue($service->isWithin($cabinet));
        self::assertTrue($service->isWithin($dir));
        self::assertTrue($service->isWithin($service));
        self::assertFalse($cabinet->isWithin($service));
        self::assertFalse($cabinet->isWithin($dir));

        $unrelated = new OrganizationUnit('OTHER_TEST', 'Autre Structure', StructureType::Direction);
        self::assertFalse($service->isWithin($unrelated));
    }

    /**
     * 2. Test liste des structures avec et sans permission
     */
    public function testStructuresListAccess(): void
    {
        $client = static::createClient();

        // Accès anonyme -> redirection login
        $client->request('GET', '/administration/structures');
        self::assertResponseRedirects('/login');

        // Accès agent sans permission STRUCTURE_VIEW -> 403
        $simpleUser = $this->createAgentWithoutPermissions('no_struct_perm');
        $client->loginUser($simpleUser);
        $client->request('GET', '/administration/structures');
        self::assertResponseStatusCodeSame(403);

        // Accès Admin -> 200 et présence du tableau des structures
        $this->loginAdmin($client);
        $crawler = $client->request('GET', '/administration/structures');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Organisation Administrative');
        self::assertGreaterThan(0, $crawler->filter('a[href="/administration/structures/new"]')->count());
    }

    /**
     * 3. Test création d'une nouvelle structure administrative
     */
    public function testStructureCreation(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        // Cleanup structure if exists from previous runs
        $em = $this->getEntityManager();
        $existing = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DIR_TEST_AUTO']);
        if ($existing) {
            $em->remove($existing);
            $em->flush();
        }

        $crawler = $client->request('GET', '/administration/structures/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Créer la structure')->form([
            'code' => 'DIR_TEST_AUTO',
            'name' => 'Direction Automatisation et Processus',
            'type' => StructureType::Direction->value,
            'description' => 'Structure de test automatisée',
            'display_order' => 10,
        ]);

        $client->submit($form);
        self::assertResponseRedirects('/administration/structures');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="alert"]', 'a été créée');

        $em->clear();
        $created = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DIR_TEST_AUTO']);
        self::assertNotNull($created);
        self::assertSame('Direction Automatisation et Processus', $created->getName());
        self::assertSame(StructureType::Direction, $created->getType());
        self::assertTrue($created->isActive());
    }

    /**
     * 4. Test modification d'une structure et protection anti-cycles
     */
    public function testStructureEditAndCyclePrevention(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $em = $this->getEntityManager();
        $unit = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DIR_TEST_AUTO']);
        if (!$unit) {
            $unit = new OrganizationUnit('DIR_TEST_AUTO', 'Direction Automatisation', StructureType::Direction);
            $em->persist($unit);
            $em->flush();
        }

        // 4.1 Modification normale
        $crawler = $client->request('GET', '/administration/structures/' . $unit->getId() . '/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer les modifications')->form([
            'name' => 'Direction Automatisation et Qualité',
            'type' => StructureType::Direction->value,
            'description' => 'Description modifiée',
            'display_order' => 15,
        ]);

        $client->submit($form);
        self::assertResponseRedirects('/administration/structures');

        $em->clear();
        $reloaded = $em->getRepository(OrganizationUnit::class)->find($unit->getId());
        self::assertSame('Direction Automatisation et Qualité', $reloaded->getName());
        self::assertSame(15, $reloaded->getDisplayOrder());

        // 4.2 Création d'une sous-structure enfant
        $child = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'SRV_TEST_SUB']);
        if (!$child) {
            $child = new OrganizationUnit('SRV_TEST_SUB', 'Sous-Service Enfant', StructureType::Service);
            $em->persist($child);
        }
        $child->setParent($reloaded);
        $em->flush();
        $childId = $child->getId();
        $reloadedId = $reloaded->getId();
        $reloadedName = $reloaded->getName();
        $reloadedTypeValue = $reloaded->getType()->value;
        $em->clear();

        // 4.3 Tentative d'affecter l'enfant comme parent du parent (cycle interdit)
        $crawlerCycle = $client->request('GET', '/administration/structures/' . $reloadedId . '/edit');
        $formCycle = $crawlerCycle->selectButton('Enregistrer les modifications')->form([
            'name' => $reloadedName,
            'type' => $reloadedTypeValue,
            'parent_id' => $childId,
        ]);

        $client->submit($formCycle);
        // Doit renvoyer une erreur sans redirection
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="alert"]', 'Une structure ne peut pas avoir comme parent');
    }

    /**
     * 5. Test activation / désactivation (toggle) d'une structure
     */
    public function testStructureToggle(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/administration/structures');
        self::assertResponseIsSuccessful();

        // Extraire le premier formulaire de toggle
        $toggleForms = $crawler->filter('form[action*="/toggle"]');
        self::assertGreaterThan(0, $toggleForms->count());

        $form = $toggleForms->first()->form();
        $actionUrl = $form->getUri();

        // Soumission du toggle
        $client->submit($form);
        self::assertResponseRedirects('/administration/structures');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="alert"]', 'Structure');
        self::assertSelectorTextContains('[role="alert"]', 'activ');
    }

    /**
     * 6. Test des référentiels métiers du PRD (Catégories et valeurs standard)
     */
    public function testReferenceCategoriesAndPredefinedItems(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $em = $this->getEntityManager();

        // Vérification des catégories dans l'enum
        $categories = ReferenceCategory::cases();
        self::assertGreaterThanOrEqual(6, count($categories));

        // Vérification des priorités PRD
        $priorities = $em->getRepository(ReferenceItem::class)->findBy(['category' => ReferenceCategory::Priority]);
        $priorityCodes = array_map(fn(ReferenceItem $i) => $i->getCode(), $priorities);
        self::assertContains('NORMALE', $priorityCodes);
        self::assertContains('URGENTE', $priorityCodes);
        self::assertContains('TRES_URGENTE', $priorityCodes);

        // Vérification des statuts de dossier PRD
        $statuses = $em->getRepository(ReferenceItem::class)->findBy(['category' => ReferenceCategory::DossierStatus]);
        $statusCodes = array_map(fn(ReferenceItem $i) => $i->getCode(), $statuses);
        self::assertContains('RECEIVED', $statusCodes);
        self::assertContains('REGISTERED', $statusCodes);
        self::assertContains('SUBMITTED', $statusCodes);

        // Consultation de la page des référentiels
        $crawler = $client->request('GET', '/administration/references?category=priorities');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Référentiels');
        self::assertGreaterThan(0, $crawler->filter('a[href="/administration/references/priorities/new"]')->count());
    }

    /**
     * 7. Test de création, modification et toggle d'un élément de référentiel
     */
    public function testReferenceCrudAndToggle(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $em = $this->getEntityManager();
        $existing = $em->getRepository(ReferenceItem::class)->findOneBy([
            'category' => ReferenceCategory::Priority,
            'code' => 'TEST_PRIORITY_P0',
        ]);
        if ($existing) {
            $em->remove($existing);
            $em->flush();
        }

        // 7.1 Création d'une nouvelle priorité
        $crawler = $client->request('GET', '/administration/references/priorities/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Ajouter au référentiel')->form([
            'code' => 'TEST_PRIORITY_P0',
            'label' => 'Urgence Absolue P0',
            'description' => 'Priorité maximale ministérielle pour test',
            'uiColor' => 'red',
            'display_order' => 99,
        ]);

        $client->submit($form);
        self::assertResponseRedirects('/administration/references?category=priorities');

        $em->clear();
        $item = $em->getRepository(ReferenceItem::class)->findOneBy([
            'category' => ReferenceCategory::Priority,
            'code' => 'TEST_PRIORITY_P0',
        ]);
        self::assertNotNull($item);
        self::assertSame('Urgence Absolue P0', $item->getLabel());
        self::assertSame('red', $item->getUiColor());
        self::assertTrue($item->isActive());

        // 7.2 Modification
        $crawlerEdit = $client->request('GET', '/administration/references/priorities/' . $item->getId() . '/edit');
        self::assertResponseIsSuccessful();

        $formEdit = $crawlerEdit->selectButton('Enregistrer les modifications')->form([
            'label' => 'Urgence Critique P0 Modifiée',
            'description' => 'Description ajustée',
            'uiColor' => 'orange',
            'display_order' => 100,
        ]);

        $client->submit($formEdit);
        self::assertResponseRedirects('/administration/references?category=priorities');

        $em->clear();
        $itemReloaded = $em->getRepository(ReferenceItem::class)->find($item->getId());
        self::assertSame('Urgence Critique P0 Modifiée', $itemReloaded->getLabel());
        self::assertSame('orange', $itemReloaded->getUiColor());

        // 7.3 Toggle actif/inactif via soumission du formulaire Metronic
        $crawlerList = $client->request('GET', '/administration/references?category=priorities');
        $toggleForm = $crawlerList->filter(sprintf('form[action$="/%d/toggle"]', $item->getId()))->form();
        $client->submit($toggleForm);
        self::assertResponseRedirects('/administration/references?category=priorities');

        $em->clear();
        $itemToggled = $em->getRepository(ReferenceItem::class)->find($item->getId());
        self::assertFalse($itemToggled->isActive());
    }

    /**
     * 8. Test de refus d'accès pour les utilisateurs non habilités
     */
    public function testReferenceAccessDeniedForUnauthorizedUser(): void
    {
        $client = static::createClient();
        $simpleUser = $this->createAgentWithoutPermissions('no_ref_perm');
        $client->loginUser($simpleUser);

        // Visualisation interdite
        $client->request('GET', '/administration/references');
        self::assertResponseStatusCodeSame(403);

        // Création interdite
        $client->request('GET', '/administration/references/priorities/new');
        self::assertResponseStatusCodeSame(403);
    }
}
