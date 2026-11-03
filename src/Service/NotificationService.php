<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\OrganizationUnit;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationRepository $notificationRepository,
        private readonly UserRepository $userRepository
    ) {}

    public function notifyUser(
        User $recipient,
        string $title,
        string $message,
        string $type = 'info',
        ?string $link = null,
        ?string $icon = null,
        ?string $idempotencyKey = null
    ): Notification {
        if ($idempotencyKey !== null) {
            $existing = $this->notificationRepository->findOneBy([
                'user' => $recipient,
                'idempotencyKey' => $idempotencyKey,
            ]);
            if ($existing !== null) {
                return $existing;
            }
        }

        $notification = new Notification($recipient, $title, $message, $type, $link, $icon);
        if ($idempotencyKey !== null) {
            $notification->setIdempotencyKey($idempotencyKey);
        }
        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        return $notification;
    }

    /**
     * @return Notification[]
     */
    public function notifyStructure(
        OrganizationUnit $structure,
        string $title,
        string $message,
        string $type = 'info',
        ?string $link = null,
        ?string $icon = null,
        ?User $excludeUser = null
    ): array {
        $users = $this->userRepository->findBy(['organizationUnit' => $structure]);
        $created = [];

        foreach ($users as $user) {
            if ($excludeUser && $user->getId() === $excludeUser->getId()) {
                continue;
            }
            $notif = new Notification($user, $title, $message, $type, $link, $icon);
            $this->entityManager->persist($notif);
            $created[] = $notif;
        }

        if (!empty($created)) {
            $this->entityManager->flush();
        }

        return $created;
    }

    /**
     * @return Notification[]
     */
    public function notifyUsersWithPermission(
        string $permissionCode,
        string $title,
        string $message,
        string $type = 'info',
        ?string $link = null,
        ?string $icon = null,
        ?OrganizationUnit $inStructure = null,
        ?User $excludeUser = null
    ): array {
        $criteria = [];
        if ($inStructure) {
            $criteria['organizationUnit'] = $inStructure;
        }

        $users = $this->userRepository->findBy($criteria);
        $created = [];

        foreach ($users as $user) {
            if ($excludeUser && $user->getId() === $excludeUser->getId()) {
                continue;
            }
            if ($user->hasPermission($permissionCode)) {
                $notif = new Notification($user, $title, $message, $type, $link, $icon);
                $this->entityManager->persist($notif);
                $created[] = $notif;
            }
        }

        if (!empty($created)) {
            $this->entityManager->flush();
        }

        return $created;
    }

    public function markAsRead(Notification $notification): void
    {
        $notification->markAsRead();
        $this->entityManager->flush();
    }

    public function markAllAsRead(User $user): int
    {
        return $this->notificationRepository->markAllAsReadForUser($user);
    }

    public function getUnreadCount(User $user): int
    {
        return $this->notificationRepository->countUnreadForUser($user);
    }

    /**
     * @return Notification[]
     */
    public function getRecentNotifications(User $user, int $limit = 20): array
    {
        return $this->notificationRepository->findRecentForUser($user, $limit);
    }
}
