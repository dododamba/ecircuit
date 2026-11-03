<?php

namespace App\Tests\Service;

use App\Entity\AccessPermission;
use App\Entity\AccessRole;
use App\Entity\OrganizationUnit;
use App\Entity\User;
use App\Enum\StructureType;
use App\Service\AccessScopeService;
use PHPUnit\Framework\TestCase;

final class AccessScopeServiceTest extends TestCase
{
    public function testPermissionComesFromRoleData(): void
    {
        $permission = new AccessPermission('USER_VIEW', 'Consulter les utilisateurs', 'Utilisateurs');
        $role = new AccessRole('ADMINISTRATEUR', 'Administrateur');
        $role->grant($permission);
        $user = new User('admin@example.test', 'Ada', 'Admin');
        $user->addAccessRole($role);
        self::assertTrue((new AccessScopeService())->hasPermission($user, 'USER_VIEW'));
        self::assertFalse((new AccessScopeService())->hasPermission($user, 'USER_DEACTIVATE'));
    }

    public function testStructureScopeFollowsHierarchy(): void
    {
        $ministry = new OrganizationUnit('MINISTERE', 'Ministère', StructureType::Ministere);
        $direction = new OrganizationUnit('DIRECTION', 'Direction', StructureType::Direction);
        $direction->setParent($ministry);
        $other = new OrganizationUnit('OTHER', 'Autre direction', StructureType::Direction);
        $user = new User('agent@example.test', 'Awa', 'Agent');
        $user->setOrganizationUnit($ministry);
        $scope = new AccessScopeService();
        self::assertTrue($scope->canAccessStructure($user, $direction));
        self::assertFalse($scope->canAccessStructure($user, $other));
    }

    public function testDossierCorrespondenceAndDocumentInterStructureScope(): void
    {
        $dgCommerce = new OrganizationUnit('DG_COMMERCE', 'DG Commerce', StructureType::DirectionGenerale);
        $dci = new OrganizationUnit('DIRECTION_COMMERCE_INTERIEUR', 'Direction Commerce Intérieur', StructureType::Direction);
        $dci->setParent($dgCommerce);

        $dgIndustrie = new OrganizationUnit('DG_INDUSTRIE_COOPERATIVES', 'DG Industrie', StructureType::DirectionGenerale);
        $ddi = new OrganizationUnit('DIRECTION_DEVELOPPEMENT_INDUSTRIEL', 'Direction Développement Industriel', StructureType::Direction);
        $ddi->setParent($dgIndustrie);

        $agentDci = new User('agent.dci@mci.gov', 'Jean', 'DCI');
        $agentDci->setOrganizationUnit($dci);

        $dgCommerceUser = new User('dg.commerce@mci.gov', 'Directeur Général', 'Commerce');
        $dgCommerceUser->setOrganizationUnit($dgCommerce);

        $sgRole = new AccessRole('SECRETAIRE_GENERAL', 'Secrétaire Général');
        $sgUser = new User('sg@mci.gov', 'Secrétaire', 'Général');
        $sgUser->addAccessRole($sgRole);

        $caseDdi = new \App\Entity\DossierCase('MCI-2026-900001', 'Dossier Industriel', \App\Enum\EntryChannel::SecretariatGeneral, new \DateTimeImmutable());
        $caseDdi->setCurrentStructure($ddi);

        $caseDci = new \App\Entity\DossierCase('MCI-2026-900002', 'Dossier Commerce', \App\Enum\EntryChannel::SecretariatGeneral, new \DateTimeImmutable());
        $caseDci->setCurrentStructure($dci);

        $docDdi = new \App\Entity\Document('indus.pdf', 'indus_1.pdf', 'application/pdf', 1024, '2026/10/indus_1.pdf', 'COURRIER_ENTRANT');
        $docDdi->setCase($caseDdi);

        $scope = new AccessScopeService();

        // Agent DCI accède à DCI mais pas à DDI
        self::assertTrue($scope->canAccessDossier($agentDci, $caseDci));
        self::assertFalse($scope->canAccessDossier($agentDci, $caseDdi));
        self::assertFalse($scope->canAccessDocument($agentDci, $docDdi));

        // DG Commerce accède à sa direction fille DCI mais pas à DDI
        self::assertTrue($scope->canAccessDossier($dgCommerceUser, $caseDci));
        self::assertFalse($scope->canAccessDossier($dgCommerceUser, $caseDdi));

        // SG a visibilité transversale sur les deux
        self::assertTrue($scope->canAccessDossier($sgUser, $caseDci));
        self::assertTrue($scope->canAccessDossier($sgUser, $caseDdi));
        self::assertTrue($scope->canAccessDocument($sgUser, $docDdi));

        // Historique de transmission : si DCI a transmis le dossier à DDI, DCI conserve un droit de consultation historique
        $transmission = new \App\Entity\CaseTransmission($caseDdi, $dci, $ddi, $agentDci, 'Transmission pour compétence');
        $caseDdi->addTransmission($transmission);
        self::assertTrue($scope->canAccessDossier($agentDci, $caseDdi));
    }
}
