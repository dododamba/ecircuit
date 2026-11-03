<?php

namespace App\Controller\Api;

use App\Entity\Notification;
use App\Entity\User;
use App\Service\NotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/api/notifications')]
class NotificationApiController extends AbstractController
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {}

    private function user(): User
    {
        /** @var User $user */
        $user = $this->getUser();
        return $user;
    }

    #[Route('', name: 'api_notifications_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit = min(50, max(1, $request->query->getInt('limit', 15)));
        $notifications = $this->notificationService->getRecentNotifications($this->user(), $limit);
        $unreadCount = $this->notificationService->getUnreadCount($this->user());

        $data = array_map(static fn(Notification $n) => [
            'id' => $n->getId(),
            'title' => $n->getTitle(),
            'message' => $n->getMessage(),
            'type' => $n->getType(),
            'icon' => $n->getIcon(),
            'link' => $n->getLink(),
            'is_read' => $n->isRead(),
            'created_at' => $n->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'time_ago' => self::formatRelativeTime($n->getCreatedAt()),
        ], $notifications);

        return new JsonResponse([
            'unread_count' => $unreadCount,
            'notifications' => $data,
        ]);
    }

    #[Route('/{id}/read', name: 'api_notification_mark_read', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markRead(Notification $notification): JsonResponse
    {
        if ($notification->getUser()->getId() !== $this->user()->getId()) {
            return new JsonResponse(['error' => 'Accès non autorisé.'], Response::HTTP_FORBIDDEN);
        }

        $this->notificationService->markAsRead($notification);

        return new JsonResponse([
            'success' => true,
            'unread_count' => $this->notificationService->getUnreadCount($this->user()),
        ]);
    }

    #[Route('/read-all', name: 'api_notifications_mark_all_read', methods: ['POST'])]
    public function markAllRead(): JsonResponse
    {
        $count = $this->notificationService->markAllAsRead($this->user());

        return new JsonResponse([
            'success' => true,
            'updated' => $count,
            'unread_count' => 0,
        ]);
    }

    private static function formatRelativeTime(\DateTimeImmutable $dateTime): string
    {
        $diff = (new \DateTimeImmutable())->getTimestamp() - $dateTime->getTimestamp();

        if ($diff < 60) {
            return "À l'instant";
        }
        if ($diff < 3600) {
            $mins = (int) round($diff / 60);
            return sprintf('Il y a %d min', $mins);
        }
        if ($diff < 86400) {
            $hours = (int) round($diff / 3600);
            return sprintf('Il y a %d h', $hours);
        }
        if ($diff < 172800) {
            return 'Hier ' . $dateTime->format('H:i');
        }

        return $dateTime->format('d/m/Y H:i');
    }
}
