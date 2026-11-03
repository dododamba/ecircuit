<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Enum\AuditAction;
use App\Enum\AuditResult;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class SecurityAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?Security $security = null
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => 'onLogout',
            KernelEvents::EXCEPTION => ['onException', 10],
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof User) {
            $user->recordLogin();
            $this->entityManager->flush();

            $this->auditLogger->log(
                event: AuditAction::LoginSuccess,
                success: true,
                user: $user,
                request: $event->getRequest(),
                context: [
                    'email' => $user->getEmail(),
                    'firewall' => $event->getFirewallName(),
                ],
                entityType: 'User',
                entityId: $user->getId(),
                result: AuditResult::Success,
                statusCode: 200
            );
        }
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $identifier = null;
        $resolvedUser = null;

        if ($event->getPassport()?->hasBadge(UserBadge::class)) {
            /** @var UserBadge $badge */
            $badge = $event->getPassport()->getBadge(UserBadge::class);
            $identifier = $badge->getUserIdentifier();
        }

        if ($identifier === null) {
            $reqUsername = $event->getRequest()->request->get('_username')
                ?? $event->getRequest()->request->get('email');
            if (is_string($reqUsername) && trim($reqUsername) !== '') {
                $identifier = strtolower(trim($reqUsername));
            }
        }

        if ($identifier !== null) {
            $userCandidate = $this->entityManager->getRepository(User::class)->findOneBy(['email' => strtolower(trim($identifier))]);
            if ($userCandidate instanceof User) {
                $resolvedUser = $userCandidate;
            }
        }

        $context = [
            'reason' => $event->getException()->getMessageKey(),
            'firewall' => $event->getFirewallName(),
        ];
        if ($identifier !== null) {
            $context['identifier'] = $identifier;
        }

        $this->auditLogger->log(
            event: AuditAction::LoginFailure,
            success: false,
            user: $resolvedUser,
            request: $event->getRequest(),
            context: $context,
            entityType: 'User',
            entityId: $resolvedUser?->getId() ?? $identifier,
            result: AuditResult::Failure,
            statusCode: 401
        );
    }

    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        $validUser = $user instanceof User ? $user : null;

        $this->auditLogger->log(
            event: AuditAction::Logout,
            success: true,
            user: $validUser,
            request: $event->getRequest(),
            context: [
                'email' => $validUser?->getEmail(),
            ],
            entityType: 'User',
            entityId: $validUser?->getId(),
            result: AuditResult::Success,
            statusCode: 200
        );
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $throwable = $event->getThrowable();
        if (
            $throwable instanceof AccessDeniedException
            || $throwable instanceof AccessDeniedHttpException
            || $throwable->getPrevious() instanceof AccessDeniedException
        ) {
            // Si la requête est déjà marquée comme auditée par un contrôleur métier, éviter le doublon
            $request = $event->getRequest();
            if ($request->attributes->getBoolean('_audit_access_denied_logged', false)) {
                return;
            }

            $user = $this->security?->getUser();
            $validUser = $user instanceof User ? $user : null;

            // Ne pas auditer comme ACCESS_DENIED une simple redirection vers /login d'un utilisateur non authentifié
            if ($validUser === null && !str_starts_with($request->getPathInfo(), '/api/')) {
                return;
            }

            $request->attributes->set('_audit_access_denied_logged', true);

            $this->auditLogger->log(
                event: AuditAction::AccessDenied,
                success: false,
                user: $validUser,
                request: $request,
                context: [
                    'route' => $request->attributes->get('_route'),
                    'uri' => $request->getRequestUri(),
                    'reason' => $throwable->getMessage(),
                    'user_structure' => $validUser?->getOrganizationUnit()?->getCode(),
                ],
                entityType: $this->extractTargetEntityFromRequest($request),
                entityId: $this->extractTargetIdFromRequest($request),
                result: AuditResult::Denied,
                statusCode: 403
            );
        }
    }

    private function extractTargetEntityFromRequest(\Symfony\Component\HttpFoundation\Request $request): ?string
    {
        $route = (string) $request->attributes->get('_route', '');
        if (str_contains($route, 'dossier')) {
            return 'DossierCase';
        }
        if (str_contains($route, 'document')) {
            return 'Document';
        }
        if (str_contains($route, 'courrier')) {
            return 'Correspondence';
        }
        if (str_contains($route, 'audit')) {
            return 'AuditLog';
        }
        if (str_contains($route, 'user')) {
            return 'User';
        }
        if (str_contains($route, 'role')) {
            return 'AccessRole';
        }
        if (str_contains($route, 'structure')) {
            return 'OrganizationUnit';
        }

        return null;
    }

    private function extractTargetIdFromRequest(\Symfony\Component\HttpFoundation\Request $request): ?string
    {
        $id = $request->attributes->get('id');
        if (is_scalar($id)) {
            return (string) $id;
        }
        if (is_object($id) && method_exists($id, 'getId')) {
            return (string) $id->getId();
        }

        return null;
    }
}

