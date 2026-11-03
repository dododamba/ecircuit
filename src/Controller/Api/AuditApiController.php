<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Enum\AuditResult;
use App\Repository\AuditLogRepository;
use App\Service\AccessScopeService;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuditApiController extends AbstractController
{
    public function __construct(
        private readonly AccessScopeService $accessScopeService,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger
    ) {
    }

    #[Route('/api/audit-logs', name: 'api_audit_logs_list', methods: ['GET'])]
    #[Route('/admin/audit', name: 'admin_short_audit_list', methods: ['GET'])]
    public function list(Request $request, AuditLogRepository $repository): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'AUDIT_VIEW');

        $limit = min(200, max(1, $request->query->getInt('limit', 50)));
        $page = max(1, $request->query->getInt('page', 1));
        $offset = ($page - 1) * $limit;
        $sort = strtoupper((string) $request->query->get('sort', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $actionFilter = trim((string) ($request->query->get('action') ?? $request->query->get('event') ?? ''));

        $filters = [
            'user_id' => $request->query->getInt('user_id') ?: null,
            'action' => $actionFilter !== '' ? $actionFilter : null,
            'entity_type' => trim((string) $request->query->get('entity_type', '')) ?: null,
            'entity_id' => trim((string) $request->query->get('entity_id', '')) ?: null,
            'result' => trim((string) $request->query->get('result', '')) ?: null,
            'structure_id' => $request->query->getInt('structure_id') ?: null,
            'role_id' => $request->query->getInt('role_id') ?: null,
            'ip' => trim((string) $request->query->get('ip', '')) ?: null,
            'route' => trim((string) $request->query->get('route', '')) ?: null,
            'date_from' => trim((string) $request->query->get('date_from', '')) ?: null,
            'date_to' => trim((string) $request->query->get('date_to', '')) ?: null,
            'q' => trim((string) $request->query->get('q', '')) ?: null,
        ];

        $logs = $repository->findByFilters($filters, $limit, $offset, $sort);

        return $this->json(array_map([$this, 'serializeAuditLog'], $logs));
    }

    #[Route('/api/audit-logs/{id}', name: 'api_audit_logs_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[Route('/audit/{id}', name: 'audit_short_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id, AuditLogRepository $repository): JsonResponse
    {
        $this->accessScopeService->assertPermission($this->currentUser(), 'AUDIT_VIEW');

        $log = $repository->find($id);
        if (!$log instanceof AuditLog) {
            return $this->json(['error' => 'Entrée d\'audit introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serializeAuditLog($log));
    }

    /**
     * Protection explicite contre toute tentative de modification ou suppression via l'API.
     */
    #[Route('/api/audit-logs/{id}', name: 'api_audit_logs_forbidden_mutation', methods: ['POST', 'PUT', 'PATCH', 'DELETE'], requirements: ['id' => '\d+'])]
    #[Route('/audit/{id}', name: 'audit_short_forbidden_mutation', methods: ['POST', 'PUT', 'PATCH', 'DELETE'], requirements: ['id' => '\d+'])]
    #[Route('/admin/audit/{id}', name: 'admin_short_audit_forbidden_mutation', methods: ['POST', 'PUT', 'PATCH', 'DELETE'], requirements: ['id' => '\d+'])]
    public function forbidMutation(int $id, Request $request): JsonResponse
    {
        $request->attributes->set('_audit_access_denied_logged', true);
        $user = $this->getUser();

        $this->auditLogger->log(
            event: AuditAction::AccessDenied,
            success: false,
            user: $user instanceof User ? $user : null,
            request: $request,
            context: [
                'reason' => 'audit_log_immutable_mutation_forbidden',
                'attempted_method' => $request->getMethod(),
                'target_audit_id' => $id,
            ],
            entityType: 'AuditLog',
            entityId: (string) $id,
            result: AuditResult::Denied,
            statusCode: Response::HTTP_FORBIDDEN
        );

        return $this->json([
            'error' => 'Opération interdite : les journaux d\'audit de sécurité sont strictement immuables et ne peuvent être ni modifiés ni supprimés.',
        ], Response::HTTP_FORBIDDEN);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAuditLog(AuditLog $log): array
    {
        return [
            'id' => $log->getId(),
            'event' => $log->getEvent(),
            'action' => $log->getAction(),
            'entityType' => $log->getEntityType(),
            'entityId' => $log->getEntityId(),
            'result' => $log->getResult(),
            'success' => $log->isSuccess(),
            'ipAddress' => $log->getIpAddress(),
            'userAgent' => $log->getUserAgent(),
            'route' => $log->getRoute(),
            'httpMethod' => $log->getHttpMethod(),
            'statusCode' => $log->getStatusCode(),
            'requestId' => $log->getRequestId(),
            'context' => $log->getContext(),
            'metadata' => $log->getMetadata(),
            'createdAt' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'timestamp' => $log->getTimestamp()->format(\DateTimeInterface::ATOM),
            'user' => $log->getUser() ? [
                'id' => $log->getUser()->getId(),
                'email' => $log->getUser()->getEmail(),
                'fullName' => $log->getUser()->getFullName(),
                'structure' => $log->getUser()->getOrganizationUnit()?->getCode(),
            ] : null,
        ];
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Non authentifié.');
        }
        return $user;
    }
}
