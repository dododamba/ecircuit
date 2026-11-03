<?php

namespace App\Security\Voter;

use App\Entity\Correspondence;
use App\Entity\Document;
use App\Entity\DossierCase;
use App\Entity\OrganizationUnit;
use App\Entity\User;
use App\Service\AccessScopeService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class StructureScopeVoter extends Voter
{
    public const ACCESS = 'STRUCTURE_ACCESS';
    public const STRUCTURE_VIEW = 'STRUCTURE_SCOPE_VIEW';
    public const DOSSIER_ACCESS = 'DOSSIER_SCOPE_ACCESS';
    public const COURRIER_ACCESS = 'COURRIER_SCOPE_ACCESS';
    public const DOCUMENT_ACCESS = 'DOCUMENT_SCOPE_ACCESS';

    public function __construct(private AccessScopeService $accessScopeService)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (in_array($attribute, [self::ACCESS, self::STRUCTURE_VIEW], true) && $subject instanceof OrganizationUnit) {
            return true;
        }
        if (in_array($attribute, [self::ACCESS, self::DOSSIER_ACCESS], true) && $subject instanceof DossierCase) {
            return true;
        }
        if (in_array($attribute, [self::ACCESS, self::COURRIER_ACCESS], true) && $subject instanceof Correspondence) {
            return true;
        }
        if (in_array($attribute, [self::ACCESS, self::DOCUMENT_ACCESS], true) && $subject instanceof Document) {
            return true;
        }

        return false;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            return false;
        }

        if ($subject instanceof OrganizationUnit) {
            return $this->accessScopeService->canAccessStructure($user, $subject);
        }

        if ($subject instanceof DossierCase) {
            return $this->accessScopeService->canAccessDossier($user, $subject);
        }

        if ($subject instanceof Correspondence) {
            return $this->accessScopeService->canAccessCorrespondence($user, $subject);
        }

        if ($subject instanceof Document) {
            return $this->accessScopeService->canAccessDocument($user, $subject);
        }

        return false;
    }
}
