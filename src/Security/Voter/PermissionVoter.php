<?php

namespace App\Security\Voter;

use App\Entity\User;
use App\Service\AccessScopeService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class PermissionVoter extends Voter
{
    public function __construct(private AccessScopeService $accessScopeService)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (str_starts_with($attribute, 'ROLE_')) {
            return false;
        }

        // Matches uppercase permission codes like USER_VIEW, ROLE_CREATE, STRUCTURE_VIEW, etc.
        return preg_match('/^[A-Z]+_[A-Z_]+$/', $attribute) === 1;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            return false;
        }

        return $this->accessScopeService->hasPermission($user, $attribute);
    }
}
