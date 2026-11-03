<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Enum\AuditResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuditLogger
{
    /**
     * Liste stricte des clés sensibles ou secrètes interdites dans les métadonnées d'audit.
     */
    private const FORBIDDEN_KEYS = [
        'password',
        'plainpassword',
        'plain_password',
        'password_confirmation',
        'passwordconfirmation',
        'current_password',
        'new_password',
        'new_password_confirmation',
        'token',
        'token_hash',
        'access_token',
        'refresh_token',
        'jwt',
        'api_secret',
        'apisecret',
        'secret',
        'private_key',
        'privatekey',
        'credentials',
        '_token',
        'csrf_token',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?Security $security = null
    ) {
    }

    /**
     * Enregistre un événement d'audit de sécurité immuable.
     *
     * @param array<string, mixed> $context
     */
    public function log(
        AuditAction|string $event,
        bool $success = true,
        ?User $user = null,
        ?Request $request = null,
        array $context = [],
        ?string $entityType = null,
        string|int|null $entityId = null,
        AuditResult|string|null $result = null,
        ?int $statusCode = null,
        bool $flush = true
    ): AuditLog {
        $actionCode = $event instanceof AuditAction ? $event->value : strtoupper(trim($event));
        $resolvedUser = $user;
        if ($resolvedUser === null && $this->security !== null) {
            $secUser = $this->security->getUser();
            if ($secUser instanceof User) {
                $resolvedUser = $secUser;
            }
        }

        $activeRequest = $request ?? $this->requestStack?->getCurrentRequest();

        $ipAddress = null;
        $userAgent = null;
        $route = null;
        $httpMethod = null;
        $requestId = null;

        if ($activeRequest !== null) {
            $ipAddress = $activeRequest->getClientIp();
            $userAgent = $activeRequest->headers->get('User-Agent');
            $httpMethod = $activeRequest->getMethod();
            $routeAttr = $activeRequest->attributes->get('_route');
            $route = is_string($routeAttr) && $routeAttr !== ''
                ? $routeAttr
                : $activeRequest->getPathInfo();

            $reqIdHeader = $activeRequest->headers->get('X-Request-ID')
                ?? $activeRequest->headers->get('X-Correlation-ID')
                ?? $activeRequest->attributes->get('_request_id');

            if (is_string($reqIdHeader) && $reqIdHeader !== '') {
                $requestId = substr($reqIdHeader, 0, 64);
            } else {
                // Identifiant de corrélation attaché à la requête courante pour grouper les audits d'une même requête HTTP
                $generatedReqId = 'req_' . substr(bin2hex(random_bytes(8)), 0, 16);
                $activeRequest->attributes->set('_request_id', $generatedReqId);
                $requestId = $generatedReqId;
            }
        }

        // Extraction intelligente de entityType / entityId depuis le contexte si non fournis explicitement
        if ($entityType === null && isset($context['entity_type']) && is_string($context['entity_type'])) {
            $entityType = $context['entity_type'];
            unset($context['entity_type']);
        }
        if ($entityId === null && isset($context['entity_id']) && (is_scalar($context['entity_id']))) {
            $entityId = (string) $context['entity_id'];
            unset($context['entity_id']);
        }

        // Inférence automatique de entityType / entityId selon l'action si non renseignés
        if ($entityType === null) {
            $entityType = $this->inferEntityType($actionCode, $context);
        }
        if ($entityId === null) {
            $entityId = $this->inferEntityId($context);
        }

        $resultStr = null;
        if ($result instanceof AuditResult) {
            $resultStr = $result->value;
            $success = ($result === AuditResult::Success);
        } elseif (is_string($result) && $result !== '') {
            $resultStr = strtoupper(trim($result));
            $success = ($resultStr === 'SUCCESS');
        } else {
            if ($actionCode === AuditAction::AccessDenied->value) {
                $resultStr = AuditResult::Denied->value;
                $success = false;
            } else {
                $resultStr = $success ? AuditResult::Success->value : AuditResult::Failure->value;
            }
        }

        if ($statusCode === null) {
            $statusCode = match ($resultStr) {
                'DENIED' => 403,
                'FAILURE' => $actionCode === 'LOGIN_FAILURE' ? 401 : 400,
                default => $activeRequest !== null ? 200 : null,
            };
        }

        $sanitizedContext = $this->sanitizeMetadata($context);

        if ($activeRequest !== null && !isset($sanitizedContext['source'])) {
            $sanitizedContext['source'] = str_starts_with((string) $activeRequest->getPathInfo(), '/api/') ? 'api' : 'web';
        } elseif ($activeRequest === null && !isset($sanitizedContext['source'])) {
            $sanitizedContext['source'] = 'cli_or_system';
        }

        if ($resolvedUser !== null && $resolvedUser->getOrganizationUnit() !== null && !isset($sanitizedContext['actor_structure'])) {
            $sanitizedContext['actor_structure'] = $resolvedUser->getOrganizationUnit()->getCode();
        }

        $log = new AuditLog(
            event: $actionCode,
            success: $success,
            user: $resolvedUser,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            context: $sanitizedContext,
            action: $actionCode,
            entityType: $entityType,
            entityId: $entityId !== null ? (string) $entityId : null,
            route: $route,
            httpMethod: $httpMethod,
            result: $resultStr,
            statusCode: $statusCode,
            requestId: $requestId
        );

        $this->entityManager->persist($log);
        if ($flush) {
            $this->entityManager->flush();
        }

        return $log;
    }

    /**
     * Nettoie récursivement le tableau de métadonnées pour exclure tout secret, mot de passe, jeton ou objet complet.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function sanitizeMetadata(array $metadata): array
    {
        $clean = [];

        foreach ($metadata as $key => $value) {
            $normalizedKey = strtolower(trim((string) $key));

            if ($this->isForbiddenKey($normalizedKey)) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitizeMetadata($value);
            } elseif (is_object($value)) {
                if ($value instanceof \DateTimeInterface) {
                    $clean[$key] = $value->format(\DateTimeInterface::ATOM);
                } elseif ($value instanceof \BackedEnum) {
                    $clean[$key] = $value->value;
                } elseif ($value instanceof \UnitEnum) {
                    $clean[$key] = $value->name;
                } elseif (method_exists($value, 'getId')) {
                    // Ne jamais sérialiser aveuglément une entité complète
                    $clean[$key] = [
                        '_class' => (new \ReflectionClass($value))->getShortName(),
                        'id' => $value->getId(),
                    ];
                } else {
                    $clean[$key] = '[object:' . $value::class . ']';
                }
            } elseif (is_string($value)) {
                if (preg_match('/^(Bearer\s+\S+|eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)/i', trim($value))) {
                    $clean[$key] = '[REDACTED]';
                } else {
                    // Tronquer les chaînes excessivement longues (ex: stack traces)
                    $clean[$key] = mb_strlen($value) > 1000 ? mb_substr($value, 0, 1000) . '…' : $value;
                }
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    private function isForbiddenKey(string $normalizedKey): bool
    {
        if (in_array($normalizedKey, self::FORBIDDEN_KEYS, true)) {
            return true;
        }

        foreach (['password', 'secret', 'token', 'private_key', 'credential', 'jwt'] as $pattern) {
            if (str_contains($normalizedKey, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function inferEntityType(string $actionCode, array $context): ?string
    {
        if (str_starts_with($actionCode, 'DOSSIER_') || str_starts_with($actionCode, 'WORKFLOW_')) {
            return 'DossierCase';
        }
        if (str_starts_with($actionCode, 'DOCUMENT_')) {
            return 'Document';
        }
        if (str_starts_with($actionCode, 'USER_') || str_starts_with($actionCode, 'ACCOUNT_') || str_starts_with($actionCode, 'PASSWORD_') || str_starts_with($actionCode, 'PROFILE_')) {
            return 'User';
        }
        if (str_starts_with($actionCode, 'LOGIN_') || $actionCode === 'LOGOUT' || $actionCode === 'SESSION_EXPIRED') {
            return 'User';
        }
        if (str_starts_with($actionCode, 'ROLE_')) {
            return 'AccessRole';
        }
        if (str_starts_with($actionCode, 'STRUCTURE_')) {
            return 'OrganizationUnit';
        }
        if (str_starts_with($actionCode, 'REFERENCE_')) {
            return 'ReferenceItem';
        }
        if (str_starts_with($actionCode, 'CIRCUIT_')) {
            return 'AdministrativeCircuit';
        }
        if (str_starts_with($actionCode, 'COURRIER_')) {
            return 'Correspondence';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function inferEntityId(array $context): ?string
    {
        foreach ([
            'case_id',
            'dossier_id',
            'document_id',
            'target_user_id',
            'created_user_id',
            'user_id',
            'role_id',
            'structure_id',
            'reference_id',
            'circuit_id',
            'id',
            'case_reference',
            'reference',
            'role_code',
            'structure_code',
            'circuit_code',
            'code',
        ] as $candidateKey) {
            if (isset($context[$candidateKey]) && is_scalar($context[$candidateKey]) && (string) $context[$candidateKey] !== '') {
                return (string) $context[$candidateKey];
            }
        }

        return null;
    }
}

