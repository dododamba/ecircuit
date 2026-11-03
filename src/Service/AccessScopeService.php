<?php

namespace App\Service;

use App\Entity\Correspondence;
use App\Entity\Document;
use App\Entity\DossierCase;
use App\Entity\OrganizationUnit;
use App\Entity\User;
use App\Enum\StructureType;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AccessScopeService
{
    private const GLOBAL_VISIBILITY_ROLES = [
        'ADMINISTRATEUR',
        'ADMIN',
        'ADMIN_GENERAL',
        'MINISTRE',
        'AGENT_CABINET',
        'CABINET',
        'SECRETAIRE_GENERAL',
        'SECRETARIAT_GENERAL',
        'SERVICE_COURRIER',
        'AGENT_COURRIER',
        'INSPECTION_GENERALE',
    ];

    public function hasPermission(User $user, string $permission): bool
    {
        if (!$user->isActive()) {
            return false;
        }

        return $user->hasPermission($permission);
    }

    public function assertPermission(User $user, string $permission): void
    {
        if (!$this->hasPermission($user, $permission)) {
            throw new AccessDeniedException(sprintf('Permission "%s" requise.', $permission));
        }
    }

    /**
     * Détermine si l'utilisateur dispose d'une visibilité transversale/institutionnelle
     * sur l'ensemble des dossiers du ministère (Administration, Ministre, Cabinet, SG, Service Courrier central).
     */
    public function hasGlobalVisibility(User $user): bool
    {
        if (!$user->isActive()) {
            return false;
        }

        foreach (self::GLOBAL_VISIBILITY_ROLES as $roleCode) {
            if ($user->hasAccessRole($roleCode)) {
                return true;
            }
        }

        $unit = $user->getOrganizationUnit();
        if ($unit !== null && in_array($unit->getType(), [
            StructureType::Ministere,
            StructureType::Cabinet,
            StructureType::SecretariatGeneral,
        ], true)) {
            return true;
        }

        return false;
    }

    public function canAccessStructure(User $user, OrganizationUnit $target): bool
    {
        if (!$user->isActive()) {
            return false;
        }

        if ($user->hasAccessRole('ADMINISTRATEUR') || $user->hasAccessRole('ADMIN') || $user->hasAccessRole('ADMIN_GENERAL')) {
            return true;
        }

        $scope = $user->getOrganizationUnit();
        if ($scope === null) {
            return false;
        }

        return $target->isWithin($scope);
    }

    public function assertStructureAccess(User $user, OrganizationUnit $target): void
    {
        if (!$this->canAccessStructure($user, $target)) {
            throw new AccessDeniedException('Accès refusé au périmètre de cette structure.');
        }
    }

    /**
     * Alias de assertStructureAccess pour compatibilité avec les appels contrôleurs existants.
     */
    public function assertStructureScope(User $user, OrganizationUnit $target): void
    {
        $this->assertStructureAccess($user, $target);
    }

    /**
     * Retourne la liste des identifiants de structures accessibles pour l'utilisateur,
     * ou null si l'utilisateur bénéficie d'une visibilité globale sur tout le ministère.
     *
     * @return list<int>|null
     */
    public function getAccessibleStructureIds(User $user): ?array
    {
        if ($this->hasGlobalVisibility($user)) {
            return null;
        }

        $scope = $user->getOrganizationUnit();
        if ($scope === null) {
            return [];
        }

        $ids = [];
        if ($scope->getId() !== null) {
            $ids[] = $scope->getId();
        }
        foreach ($scope->getAllDescendants() as $descendant) {
            if ($descendant->getId() !== null) {
                $ids[] = $descendant->getId();
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Vérifie si un utilisateur est autorisé à consulter ou manipuler un dossier selon :
     * 1. Sa visibilité globale ou institutionnelle ;
     * 2. Son affectation individuelle au dossier ;
     * 3. Sa visibilité hiérarchique sur la structure détentrice actuelle du dossier ;
     * 4. Sa participation (ou celle de sa structure) à une étape antérieure du circuit (transmissions, événements, workflow).
     */
    public function canAccessDossier(User $user, DossierCase $case): bool
    {
        if (!$user->isActive()) {
            return false;
        }

        if ($this->hasGlobalVisibility($user)) {
            return true;
        }

        // 1. Affectation individuelle directe
        $assignee = $case->getCurrentAssignee();
        if ($assignee !== null && $assignee->getId() !== null && $assignee->getId() === $user->getId()) {
            return true;
        }

        // 2. Visibilité sur la structure actuellement responsable du dossier
        $currentStructure = $case->getCurrentStructure();
        if ($currentStructure !== null && $this->canAccessStructure($user, $currentStructure)) {
            return true;
        }

        // Tolérance pour les dossiers sans structure affectée lorsque l'utilisateur n'a pas de structure restrictive
        if ($currentStructure === null && $user->getOrganizationUnit() === null) {
            return true;
        }

        // 3. Participation antérieure aux transmissions du dossier
        foreach ($case->getTransmissions() as $transmission) {
            if ($transmission->getTransmittedBy()?->getId() !== null && $transmission->getTransmittedBy()?->getId() === $user->getId()) {
                return true;
            }
            if ($transmission->getSourceUnit() !== null && $this->canAccessStructure($user, $transmission->getSourceUnit())) {
                return true;
            }
            if ($transmission->getTargetUnit() !== null && $this->canAccessStructure($user, $transmission->getTargetUnit())) {
                return true;
            }
        }

        // 4. Participation antérieure aux événements métiers du dossier
        foreach ($case->getEvents() as $event) {
            if ($event->getActor()?->getId() !== null && $event->getActor()?->getId() === $user->getId()) {
                return true;
            }
            if ($event->getOrganizationUnit() !== null && $this->canAccessStructure($user, $event->getOrganizationUnit())) {
                return true;
            }
        }

        // 5. Participation antérieure dans l'instance de workflow
        $workflowInstance = $case->getWorkflowInstance();
        if ($workflowInstance !== null) {
            foreach ($workflowInstance->getHistories() as $history) {
                if ($history->getActor()?->getId() !== null && $history->getActor()?->getId() === $user->getId()) {
                    return true;
                }
                if ($history->getOrganizationUnit() !== null && $this->canAccessStructure($user, $history->getOrganizationUnit())) {
                    return true;
                }
            }
            foreach ($workflowInstance->getTasks() as $task) {
                if ($task->getTargetUser()?->getId() !== null && $task->getTargetUser()?->getId() === $user->getId()) {
                    return true;
                }
                if ($task->getTargetStructure() !== null && $this->canAccessStructure($user, $task->getTargetStructure())) {
                    return true;
                }
            }
        }

        return false;
    }

    public function assertDossierAccess(User $user, DossierCase $case): void
    {
        if (!$this->canAccessDossier($user, $case)) {
            throw new AccessDeniedException(sprintf(
                'Accès inter-structures refusé : le dossier %s ne relève pas de votre périmètre organisationnel.',
                $case->getReference()
            ));
        }
    }

    public function canAccessCorrespondence(User $user, Correspondence $correspondence): bool
    {
        if (!$user->isActive()) {
            return false;
        }

        if ($this->hasGlobalVisibility($user)) {
            return true;
        }

        if ($correspondence->getRegisteredBy()?->getId() !== null && $correspondence->getRegisteredBy()?->getId() === $user->getId()) {
            return true;
        }

        if ($correspondence->getValidatedBy()?->getId() !== null && $correspondence->getValidatedBy()?->getId() === $user->getId()) {
            return true;
        }

        if (method_exists($correspondence, 'getIssuingStructure') && $correspondence->getIssuingStructure() !== null) {
            if ($this->canAccessStructure($user, $correspondence->getIssuingStructure())) {
                return true;
            }
        }

        if ($correspondence->getCase() !== null) {
            return $this->canAccessDossier($user, $correspondence->getCase());
        }

        return $user->getOrganizationUnit() === null;
    }

    public function assertCorrespondenceAccess(User $user, Correspondence $correspondence): void
    {
        if (!$this->canAccessCorrespondence($user, $correspondence)) {
            throw new AccessDeniedException(sprintf(
                'Accès inter-structures refusé : le courrier %s ne relève pas de votre périmètre organisationnel.',
                $correspondence->getReference() ?? '#' . $correspondence->getId()
            ));
        }
    }

    public function canAccessDocument(User $user, Document $document): bool
    {
        if (!$user->isActive()) {
            return false;
        }

        if ($this->hasGlobalVisibility($user)) {
            return true;
        }

        if ($document->getUploadedBy()?->getId() !== null && $document->getUploadedBy()?->getId() === $user->getId()) {
            return true;
        }

        if ($document->getCase() !== null) {
            return $this->canAccessDossier($user, $document->getCase());
        }

        if ($document->getCorrespondence() !== null) {
            return $this->canAccessCorrespondence($user, $document->getCorrespondence());
        }

        return $user->getOrganizationUnit() === null;
    }

    public function assertDocumentAccess(User $user, Document $document): void
    {
        if (!$this->canAccessDocument($user, $document)) {
            throw new AccessDeniedException('Accès inter-structures refusé : vous n\'êtes pas autorisé à consulter ou télécharger ce document.');
        }
    }

    /**
     * @param list<OrganizationUnit> $units
     * @return list<OrganizationUnit>
     */
    public function filterAccessibleStructures(User $user, array $units): array
    {
        if ($this->hasGlobalVisibility($user)) {
            return $units;
        }

        $scope = $user->getOrganizationUnit();
        if ($scope === null) {
            return [];
        }

        return array_values(array_filter($units, fn (OrganizationUnit $u) => $u->isWithin($scope)));
    }
}
