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
    public const ADMIN_EMAIL = 'admin@mci.local';
    public const ADMIN_PASSWORD = 'AdminDevSecret2026!';
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

        // 2. Récupération ou création des structures de rattachement
        $ministere = $manager->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'MINISTERE']);
        if (!$ministere) {
            $ministere = new OrganizationUnit('MINISTERE', 'Ministère du Commerce et de l’Industrie', StructureType::Ministere);
            $manager->persist($ministere);
        }

        $structure = $manager->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DIRECTION_COMMERCE_INTERIEUR']);
        if (!$structure) {
            $structure = new OrganizationUnit('DIRECTION_COMMERCE_INTERIEUR', 'Direction du Commerce Intérieur', StructureType::Direction);
            $structure->setParent($ministere);
            $manager->persist($structure);
        }

        // 3. Création ou mise à jour de l'administrateur principal
        $mainAdmin = $manager->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);
        if (!$mainAdmin) {
            $mainAdmin = new User(self::ADMIN_EMAIL, 'Administrateur', 'MCI');
            $manager->persist($mainAdmin);
        }

        $mainAdmin->setUsername('admin');
        $mainAdmin->setFirstName('Administrateur');
        $mainAdmin->setLastName('MCI');
        $mainAdmin->setPhone('+241 01 00 00 00');
        $mainAdmin->setStatus(AccountStatus::Active);
        $mainAdmin->setOrganizationUnit($ministere);
        $mainAdmin->addAccessRole($roleAdmin);
        $mainAdmin->setPassword($this->passwordHasher->hashPassword($mainAdmin, self::ADMIN_PASSWORD));

        // 4. Création ou mise à jour de l'utilisateur de test standard
        $testUser = $manager->getRepository(User::class)->findOneBy(['email' => self::TEST_USER_EMAIL]);
        if (!$testUser) {
            $testUser = new User(self::TEST_USER_EMAIL, 'Jean', 'KOUASSI');
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

        // 5. Création ou mise à jour d'un administrateur de test
        $adminUser = $manager->getRepository(User::class)->findOneBy(['email' => self::TEST_ADMIN_EMAIL]);
        if (!$adminUser) {
            $adminUser = new User(self::TEST_ADMIN_EMAIL, 'Administrateur', 'TEST');
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

