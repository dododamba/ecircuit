<?php

namespace App\Tests\Controller;

use App\Entity\AccessRole;
use App\Entity\DossierCase;
use App\Entity\Notification;
use App\Entity\OrganizationUnit;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\EntryChannel;
use App\Enum\StructureType;
use App\Enum\WorkflowAction;
use App\Service\NotificationService;
use App\Service\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class NotificationTest extends WebTestCase
{
    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function getPasswordHasher(): UserPasswordHasherInterface
    {
        return self::getContainer()->get(UserPasswordHasherInterface::class);
    }

    private function createAgent(string $email, string $roleCode): User
    {
        $em = $this->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $user = new User($email, 'Agent', $roleCode);
            $user->setPassword($this->getPasswordHasher()->hashPassword($user, 'Password123!'));
            $user->setStatus(AccountStatus::Active);

            $role = $em->getRepository(AccessRole::class)->findOneBy(['code' => $roleCode]);
            if ($role) {
                $user->addAccessRole($role);
            }

            $em->persist($user);
            $em->flush();
        }

        return $user;
    }

    public function testNotificationApiListAndCount(): void
    {
        $client = static::createClient();
        $user = $this->createAgent('notif_user1@mci.local', 'AGENT_CABINET');
        $client->loginUser($user);

        /** @var NotificationService $notifService */
        $notifService = self::getContainer()->get(NotificationService::class);
        $notifService->notifyUser($user, 'Alerte test 1', 'Message de test notification', 'info', '/dossiers/1');

        $client->request('GET', '/api/notifications');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('unread_count', $data);
        self::assertArrayHasKey('notifications', $data);
        self::assertGreaterThanOrEqual(1, $data['unread_count']);
        self::assertSame('Alerte test 1', $data['notifications'][0]['title']);
    }

    public function testNotificationMarkAsRead(): void
    {
        $client = static::createClient();
        $user = $this->createAgent('notif_user2@mci.local', 'AGENT_CABINET');
        $client->loginUser($user);

        /** @var NotificationService $notifService */
        $notifService = self::getContainer()->get(NotificationService::class);
        $notif = $notifService->notifyUser($user, 'Action requise test', 'Veuillez instruire ce dossier', 'action_required', '/dossiers/2');

        self::assertFalse($notif->isRead());

        $client->request('POST', '/api/notifications/' . $notif->getId() . '/read');
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);

        $em = $this->getEntityManager();
        $em->clear();
        $reloaded = $em->getRepository(Notification::class)->find($notif->getId());
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isRead());
    }

    public function testNotificationMarkAllAsRead(): void
    {
        $client = static::createClient();
        $user = $this->createAgent('notif_user3@mci.local', 'AGENT_CABINET');
        $client->loginUser($user);

        /** @var NotificationService $notifService */
        $notifService = self::getContainer()->get(NotificationService::class);
        $notifService->notifyUser($user, 'Alerte 1', 'Msg 1');
        $notifService->notifyUser($user, 'Alerte 2', 'Msg 2');

        $client->request('POST', '/api/notifications/read-all');
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
        self::assertSame(0, $data['unread_count']);

        $unreadCount = $notifService->getUnreadCount($user);
        self::assertSame(0, $unreadCount);
    }

    public function testWorkflowTriggersInAppNotification(): void
    {
        $client = static::createClient();
        $em = $this->getEntityManager();
        $agentCabinet = $this->createAgent('cabinet_agent_notif@mci.local', 'AGENT_CABINET');
        $ministre = $this->createAgent('ministre_notif@mci.local', 'MINISTRE');
        $agentSg = $this->createAgent('sg_agent_notif@mci.local', 'AGENT_SG');

        $cabStructure = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'CABINET']);
        $sgStructure = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'SECRETARIAT_GENERAL']);
        $minStructure = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'MINISTERE']);

        if ($cabStructure) {
            $agentCabinet->setOrganizationUnit($cabStructure);
        }
        if ($sgStructure) {
            $agentSg->setOrganizationUnit($sgStructure);
        }
        if ($minStructure) {
            $ministre->setOrganizationUnit($minStructure);
        }
        $em->flush();

        $ref = 'MCI-' . date('Y') . '-' . mt_rand(700000, 799999);
        $case = new DossierCase($ref, 'Dossier test notifications', EntryChannel::Cabinet, new \DateTimeImmutable());
        if ($cabStructure) {
            $case->setCurrentStructure($cabStructure);
        }
        $case->setStatusCode('REGISTERED');
        $em->persist($case);
        $em->flush();

        /** @var WorkflowService $workflowService */
        $workflowService = self::getContainer()->get(WorkflowService::class);
        $workflowService->start($case, null, $agentCabinet);
        $workflowService->submit($case, $agentCabinet, 'Soumission initiale');

        /** @var NotificationService $notifService */
        $notifService = self::getContainer()->get(NotificationService::class);
        $initialUnread = $notifService->getUnreadCount($agentSg);

        // Ministre transmet au SG avec notification automatique
        $workflowService->forward($case, $ministre, $sgStructure, 'Transmission pour orientation');

        $newUnread = $notifService->getUnreadCount($agentSg);
        self::assertGreaterThan($initialUnread, $newUnread);

        $recentNotifs = $notifService->getRecentNotifications($agentSg, 5);
        self::assertNotEmpty($recentNotifs);
        self::assertStringContainsString($case->getReference(), $recentNotifs[0]->getTitle());
    }
}
