<?php

namespace App\Tests\Security;

use App\Entity\AccessPermission;
use App\Entity\AccessRole;
use App\Entity\OrganizationUnit;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\StructureType;
use App\Service\AccessScopeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class IamTest extends WebTestCase
{
    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function getPasswordHasher(): UserPasswordHasherInterface
    {
        return self::getContainer()->get(UserPasswordHasherInterface::class);
    }

    /**
     * 1. Test Login Valide
     */
    public function testLoginSuccess(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => 'admin@mci.local',
            '_password' => 'TestAdminSecret123!',
        ]);

        $client->submit($form);
        self::assertResponseRedirects('/');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tableau de bord');
    }

    /**
     * 2. Test Login Invalide
     */
    public function testLoginFailureWithInvalidCredentials(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => 'admin@mci.local',
            '_password' => 'WrongPassword!',
        ]);

        $client->submit($form);
        self::assertResponseRedirects('/login');

        $client->followRedirect();
        self::assertSelectorExists('.kt-alert-destructive');
    }

    /**
     * 3. Test Logout
     */
    public function testLogout(): void
    {
        $client = static::createClient();
        $em = $this->getEntityManager();
        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'admin@mci.local']);
        self::assertNotNull($admin);

        $client->loginUser($admin);
        $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        // Logout
        $client->request('POST', '/logout', ['_token' => $client->getContainer()->get('security.csrf.token_manager')->getToken('logout')->getValue()]);
        self::assertResponseRedirects('http://localhost/login');

        // Trying to access protected route after logout redirects to login
        $client->request('GET', '/dashboard');
        self::assertResponseRedirects('http://localhost/login');
    }

    /**
     * 4. Test Route Protégée pour anonyme
     */
    public function testProtectedRoutesRequireAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/dashboard');
        self::assertResponseRedirects('http://localhost/login');

        $client->request('GET', '/profile');
        self::assertResponseRedirects('http://localhost/login');

        $client->request('GET', '/administration/users');
        self::assertResponseRedirects('http://localhost/login');
    }

    /**
     * 5. Test Utilisateur Désactivé
     */
    public function testDisabledUserCannotLogin(): void
    {
        $client = static::createClient();
        $em = $this->getEntityManager();
        $hasher = $this->getPasswordHasher();

        $email = 'disabled_' . uniqid() . '@mci.local';
        $user = new User($email, 'Disabled', 'User');
        $user->setPassword($hasher->hashPassword($user, 'Password123!'));
        $user->setStatus(AccountStatus::Inactive);
        $em->persist($user);
        $em->flush();

        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => $email,
            '_password' => 'Password123!',
        ]);

        $client->submit($form);
        self::assertResponseRedirects('/login');

        $client->followRedirect();
        self::assertSelectorExists('.kt-alert-destructive');
    }

    /**
     * 6. Test Permission Insuffisante (403 Access Denied)
     */
    public function testAccessDeniedWhenPermissionInsufficient(): void
    {
        $client = static::createClient();
        $em = $this->getEntityManager();
        $hasher = $this->getPasswordHasher();

        $email = 'agent_noperm_' . uniqid() . '@mci.local';
        $user = new User($email, 'Agent', 'NoPerm');
        $user->setPassword($hasher->hashPassword($user, 'Password123!'));
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        // Accessing administration/users requires USER_VIEW
        $client->request('GET', '/administration/users');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * 7. Test Accès Autorisé avec Permission
     */
    public function testAccessGrantedWithPermission(): void
    {
        $client = static::createClient();
        $em = $this->getEntityManager();
        $hasher = $this->getPasswordHasher();

        $email = 'agent_withperm_' . uniqid() . '@mci.local';
        $user = new User($email, 'Agent', 'WithPerm');
        $user->setPassword($hasher->hashPassword($user, 'Password123!'));

        // Assign role with USER_VIEW
        $role = $em->getRepository(AccessRole::class)->findOneBy(['code' => 'ADMINISTRATEUR']);
        self::assertNotNull($role);
        $user->addAccessRole($role);

        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/administration/users');
        self::assertResponseIsSuccessful();
    }

    /**
     * 8. Test Changement de Mot de Passe via Mon Profil
     */
    public function testPasswordChangeInProfile(): void
    {
        $client = static::createClient();
        $em = $this->getEntityManager();
        $hasher = $this->getPasswordHasher();

        $email = 'pwd_test_' . uniqid() . '@mci.local';
        $user = new User($email, 'Paul', 'Password');
        $user->setPassword($hasher->hashPassword($user, 'InitialPassword123!'));
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();

        // 8a. Erreur si mot de passe actuel invalide
        $form1 = $crawler->selectButton('Enregistrer le mot de passe')->form([
            'current_password' => 'WrongCurrent!',
            'new_password' => 'NewPassword123!',
            'new_password_confirmation' => 'NewPassword123!',
        ]);
        $client->submit($form1);
        self::assertResponseRedirects('/profile');
        $crawlerAfter = $client->followRedirect();
        self::assertSelectorExists('.kt-alert-destructive');

        // 8b. Succès avec bon mot de passe
        $form2 = $crawlerAfter->selectButton('Enregistrer le mot de passe')->form([
            'current_password' => 'InitialPassword123!',
            'new_password' => 'UpdatedSecret2026!',
            'new_password_confirmation' => 'UpdatedSecret2026!',
        ]);
        $client->submit($form2);
        self::assertResponseRedirects('/profile');
        $client->followRedirect();
        self::assertSelectorExists('.kt-alert-success');

        // Verify user can now log in with the new password
        $updatedUser = $this->getEntityManager()->getRepository(User::class)->find($user->getId());
        self::assertNotNull($updatedUser);
        self::assertTrue($hasher->isPasswordValid($updatedUser, 'UpdatedSecret2026!'));
    }

    /**
     * 9. Test Maintien de Session
     */
    public function testSessionPersistenceAcrossRequests(): void
    {
        $client = static::createClient();
        $em = $this->getEntityManager();
        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'admin@mci.local']);
        self::assertNotNull($admin);

        $client->loginUser($admin);

        $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Espace personnel et sécurité du compte');
    }

    /**
     * 10. Test Rattachement Structure / Rôle
     */
    public function testStructureAndRoleAttachment(): void
    {
        $em = $this->getEntityManager();

        $ministry = new OrganizationUnit('TEST_MIN_' . uniqid(), 'Ministère Test', StructureType::Ministere);
        $direction = new OrganizationUnit('TEST_DIR_' . uniqid(), 'Direction Test', StructureType::Direction);
        $direction->setParent($ministry);
        $service = new OrganizationUnit('TEST_SRV_' . uniqid(), 'Service Test', StructureType::Service);
        $service->setParent($direction);
        $outside = new OrganizationUnit('TEST_OUT_' . uniqid(), 'Autre Direction', StructureType::Direction);

        $user = new User('user_org_' . uniqid() . '@mci.local', 'Jean', 'Test');
        $user->setPassword('SecretPassword123!');
        $user->setOrganizationUnit($direction);

        $roleCode = 'TEST_ROLE_' . uniqid();
        $role = new AccessRole($roleCode, 'Rôle Test');
        $permission = $em->getRepository(AccessPermission::class)->findOneBy(['code' => 'COURRIER_VIEW']);
        if (!$permission) {
            $permission = new AccessPermission('COURRIER_VIEW', 'Consulter courriers', 'Courrier');
            $em->persist($permission);
        }
        $role->grant($permission);
        $user->addAccessRole($role);

        $em->persist($ministry);
        $em->persist($direction);
        $em->persist($service);
        $em->persist($outside);
        $em->persist($role);
        $em->persist($user);
        $em->flush();

        // Vérification des rôles et permissions
        self::assertTrue($user->hasAccessRole($roleCode));
        self::assertTrue($user->hasPermission('COURRIER_VIEW'));
        self::assertFalse($user->hasPermission('DOSSIER_DELETE'));

        // Vérification de la portée organisationnelle
        $scope = new AccessScopeService();
        self::assertTrue($scope->canAccessStructure($user, $direction));
        self::assertTrue($scope->canAccessStructure($user, $service)); // Enfant
        self::assertFalse($scope->canAccessStructure($user, $outside)); // Hors périmètre
    }
}
