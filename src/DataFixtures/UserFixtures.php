<?php

namespace App\DataFixtures;

use App\Entity\AccessRole;
use App\Entity\OrganizationUnit;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\StructureType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    public const TEST_USER_EMAIL = 'user.test@mci.gov';
    public const TEST_USER_PASSWORD = 'Password123!';
    public const TEST_ADMIN_EMAIL = 'admin.test@mci.gov';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // 1. Récupération ou création des rôles nécessaires
        $roleAgent = $manager->getRepository(AccessRole::class)->findOneBy(['code' => 'AGENT_TRAITEMENT']);
        if (!$roleAgent) {
            $roleAgent = new AccessRole('AGENT_TRAITEMENT', 'Agent de traitement', 'Agent en charge de l\'instruction des dossiers.');
            $manager->persist($roleAgent);
        }

        $roleAdmin = $manager->getRepository(AccessRole::class)->findOneBy(['code' => 'ADMINISTRATEUR']);
        if (!$roleAdmin) {
            $roleAdmin = new AccessRole('ADMINISTRATEUR', 'Administrateur', 'Administration système et gestion des habilitations.');
            $manager->persist($roleAdmin);
        }

        // 2. Récupération ou création de la structure de rattachement
        $structure = $manager->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DIRECTION_COMMERCE_INTERIEUR']);
        if (!$structure) {
            $structure = $manager->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'MINISTERE']);
        }
        if (!$structure) {
            $structure = new OrganizationUnit('Direction du Commerce Intérieur', 'DIRECTION_COMMERCE_INTERIEUR', StructureType::Direction);
            $manager->persist($structure);
        }

        // 3. Création ou mise à jour de l'utilisateur de test standard
        $testUser = $manager->getRepository(User::class)->findOneBy(['email' => self::TEST_USER_EMAIL]);
        if (!$testUser) {
            $testUser = new User(self::TEST_USER_EMAIL, 'Test', 'UTILISATEUR');
            $manager->persist($testUser);
        }

        $testUser->setUsername('user_test');
        $testUser->setFirstName('Jean');
        $testUser->setLastName('KOUASSI');
        $testUser->setPhone('+225 07 01 02 03 04');
        $testUser->setStatus(AccountStatus::Active);
        $testUser->setOrganizationUnit($structure);
        $testUser->addAccessRole($roleAgent);
        $testUser->setPassword($this->passwordHasher->hashPassword($testUser, self::TEST_USER_PASSWORD));

        // 4. Création ou mise à jour d'un administrateur de test
        $adminUser = $manager->getRepository(User::class)->findOneBy(['email' => self::TEST_ADMIN_EMAIL]);
        if (!$adminUser) {
            $adminUser = new User(self::TEST_ADMIN_EMAIL, 'Admin', 'TEST');
            $manager->persist($adminUser);
        }

        $adminUser->setUsername('admin_test');
        $adminUser->setFirstName('Administrateur');
        $adminUser->setLastName('TEST');
        $adminUser->setPhone('+225 07 00 00 00 01');
        $adminUser->setStatus(AccountStatus::Active);
        $adminUser->setOrganizationUnit($structure);
        $adminUser->addAccessRole($roleAdmin);
        $adminUser->setPassword($this->passwordHasher->hashPassword($adminUser, self::TEST_USER_PASSWORD));

        $manager->flush();
    }
}

