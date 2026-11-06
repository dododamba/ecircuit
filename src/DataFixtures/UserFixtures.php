<?php

namespace App\DataFixtures;

use App\Entity\AccessPermission;
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
        // 0. Permissions granulaires essentielles
        $permissionDefs = [
            'AUTH_LOGIN' => ['Connexion au système', 'Authentification'],
            'AUTH_LOGOUT' => ['Déconnexion', 'Authentification'],
            'USER_VIEW' => ['Consulter les utilisateurs', 'Utilisateurs'],
            'USER_CREATE' => ['Créer un compte utilisateur', 'Utilisateurs'],
            'USER_UPDATE' => ['Modifier un utilisateur', 'Utilisateurs'],
            'USER_ACTIVATE' => ['Activer un compte utilisateur', 'Utilisateurs'],
            'USER_DEACTIVATE' => ['Désactiver un compte utilisateur', 'Utilisateurs'],
            'USER_RESET_PASSWORD' => ['Réinitialiser le mot de passe', 'Utilisateurs'],
            'ROLE_VIEW' => ['Consulter les rôles', 'Accès'],
            'ROLE_CREATE' => ['Créer un rôle', 'Accès'],
            'ROLE_UPDATE' => ['Modifier un rôle', 'Accès'],
            'ROLE_EDIT' => ['Modifier un rôle', 'Accès'],
            'PERMISSION_VIEW' => ['Consulter les permissions', 'Accès'],
            'PERMISSION_ASSIGN' => ['Affecter des permissions aux rôles', 'Accès'],
            'STRUCTURE_VIEW' => ['Consulter les structures administratives', 'Organisation'],
            'STRUCTURE_CREATE' => ['Créer une structure', 'Organisation'],
            'STRUCTURE_UPDATE' => ['Modifier une structure', 'Organisation'],
            'REFERENCE_VIEW' => ['Consulter les référentiels métiers', 'Référentiels'],
            'REFERENCE_CREATE' => ['Créer un élément de référentiel', 'Référentiels'],
            'REFERENCE_UPDATE' => ['Modifier un élément de référentiel', 'Référentiels'],
            'CIRCUIT_VIEW' => ['Consulter les circuits administratifs', 'Circuits'],
            'CIRCUIT_CREATE' => ['Créer un circuit administratif', 'Circuits'],
            'CIRCUIT_UPDATE' => ['Modifier un circuit administratif', 'Circuits'],
            'AUDIT_VIEW' => ['Consulter les journaux d’audit', 'Audit'],
            'COURRIER_VIEW' => ['Consulter les courriers', 'Courrier'],
            'COURRIER_CREATE' => ['Enregistrer un courrier entrant', 'Courrier'],
            'COURRIER_EDIT' => ['Modifier un courrier', 'Courrier'],
            'COURRIER_TRANSMIT' => ['Transmettre un courrier', 'Courrier'],
            'DOSSIER_VIEW' => ['Consulter les courriers et dossiers', 'Dossiers'],
            'DOSSIER_CREATE' => ['Créer / enregistrer un dossier', 'Dossiers'],
            'DOSSIER_EDIT' => ['Modifier un dossier', 'Dossiers'],
            'DOSSIER_ASSIGN' => ['Affecter un dossier à une structure ou un agent', 'Dossiers'],
            'DOSSIER_CLOSE' => ['Clôturer un dossier', 'Dossiers'],
            'DOSSIER_ARCHIVE' => ['Archiver un dossier', 'Dossiers'],
            'DOSSIER_ANNOTATE' => ['Annoter un dossier', 'Dossiers'],
            'DOSSIER_FORWARD' => ['Transmettre / orienter un dossier', 'Dossiers'],
            'DOSSIER_TREAT' => ['Traiter un dossier', 'Dossiers'],
            'DOSSIER_VALIDATE' => ['Valider un dossier', 'Dossiers'],
            'DOSSIER_SIGN' => ['Signer un document / dossier', 'Dossiers'],
            'DOCUMENT_VIEW' => ['Consulter les documents et pièces jointes', 'Documents'],
            'DOCUMENT_UPLOAD' => ['Téléverser des pièces et documents', 'Documents'],
            'DOCUMENT_DOWNLOAD' => ['Télécharger les documents', 'Documents'],
            'DOCUMENT_DELETE' => ['Supprimer un document', 'Documents'],
        ];

        $permissions = [];
        foreach ($permissionDefs as $code => [$label, $module]) {
            $perm = $manager->getRepository(AccessPermission::class)->findOneBy(['code' => $code]);
            if (!$perm) {
                $perm = new AccessPermission($code, $label, $module);
                $manager->persist($perm);
            }
            $permissions[$code] = $perm;
        }

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

        foreach ($permissions as $permission) {
            $roleAdmin->grant($permission);
        }

        foreach ([
            'AUTH_LOGIN', 'AUTH_LOGOUT', 'STRUCTURE_VIEW', 'REFERENCE_VIEW',
            'COURRIER_VIEW', 'DOSSIER_VIEW', 'DOSSIER_TREAT', 'DOSSIER_FORWARD',
            'DOCUMENT_VIEW', 'DOCUMENT_UPLOAD', 'DOCUMENT_DOWNLOAD',
        ] as $code) {
            if (isset($permissions[$code])) {
                $roleAgent->grant($permissions[$code]);
            }
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

