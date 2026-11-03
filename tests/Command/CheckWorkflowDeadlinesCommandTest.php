<?php

namespace App\Tests\Command;

use App\Command\CheckWorkflowDeadlinesCommand;
use App\Entity\AccessRole;
use App\Entity\AuditLog;
use App\Entity\CaseEvent;
use App\Entity\DossierCase;
use App\Entity\Notification;
use App\Entity\OrganizationUnit;
use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowHistory;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Entity\WorkflowTask;
use App\Enum\AccountStatus;
use App\Enum\CaseEventType;
use App\Enum\EntryChannel;
use App\Enum\StructureType;
use App\Enum\WorkflowAction;
use App\Enum\WorkflowTaskStatus;
use App\Service\AuditLogger;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CheckWorkflowDeadlinesCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testCheckDeadlinesCommandDetectsAndEscalatesOverdueTasks(): void
    {
        $application = new Application(self::$kernel);

        // 1. Setup a test structure, superior structure, users, workflow, case and overdue task
        $uid = uniqid();
        $sgUnit = $this->em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'SECRETARIAT_GENERAL']);
        if (!$sgUnit) {
            $sgUnit = new OrganizationUnit('Secrétariat Général', 'SECRETARIAT_GENERAL', StructureType::SecretariatGeneral);
            $this->em->persist($sgUnit);
        }

        $dirUnit = new OrganizationUnit('Direction Test ' . $uid, 'DIR_TEST_' . $uid, StructureType::Direction);
        $dirUnit->setParent($sgUnit);
        $this->em->persist($dirUnit);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $superiorUser = new User('superior_' . $uid . '@mci.local', 'Supérieur', 'SG');
        $superiorUser->setPassword($hasher->hashPassword($superiorUser, 'Password123!'));
        $superiorUser->setStatus(AccountStatus::Active);
        $superiorUser->setOrganizationUnit($sgUnit);
        $this->em->persist($superiorUser);

        $agentUser = new User('agent_' . $uid . '@mci.local', 'Agent', 'Directeur');
        $agentUser->setPassword($hasher->hashPassword($agentUser, 'Password123!'));
        $agentUser->setStatus(AccountStatus::Active);
        $agentUser->setOrganizationUnit($dirUnit);
        $this->em->persist($agentUser);

        $caseRef = 'MCI-TEST-DL-' . uniqid();
        $case = new DossierCase($caseRef, 'Dossier Test Échéance Dépassée', EntryChannel::SecretariatGeneral, new \DateTimeImmutable('-5 days'));
        $case->setPriorityCode('HIGH');
        $case->setCurrentStructure($dirUnit);
        $case->setCurrentAssignee($agentUser);
        // Past deadline (2 days ago)
        $pastDeadline = new \DateTimeImmutable('-2 days');
        $case->setDeadline($pastDeadline);
        $this->em->persist($case);

        $wf = $this->em->getRepository(Workflow::class)->findOneBy(['code' => 'WORKFLOW_CABINET_STANDARD']);
        self::assertNotNull($wf, 'Le workflow standard doit exister');
        $initialStep = $wf->getInitialStep();
        self::assertNotNull($initialStep);

        $instance = new WorkflowInstance($case, $wf, $initialStep);
        $this->em->persist($instance);

        $task = new WorkflowTask($instance, $initialStep, $dirUnit, $agentUser);
        $task->setDeadline($pastDeadline);
        $this->em->persist($task);
        $this->em->flush();

        $taskId = $task->getId();
        $caseId = $case->getId();

        // 2. Test Dry Run mode first
        $command = $application->find('app:workflow:check-deadlines');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--dry-run' => true, '--case-ref' => $caseRef]);

        self::assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        self::assertStringContainsString('MODE SIMULATION', $output);
        self::assertStringContainsString($caseRef, $output);

        // Verify task was NOT escalated in dry-run
        $this->em->clear();
        $reloadedTask = $this->em->getRepository(WorkflowTask::class)->find($taskId);
        self::assertNotNull($reloadedTask);
        self::assertFalse($reloadedTask->isEscalated());

        // 3. Test Actual execution
        $commandTester->execute(['--case-ref' => $caseRef]);
        self::assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        self::assertStringContainsString('escaladée(s)', $output);

        $this->em->clear();
        $escalatedTask = $this->em->getRepository(WorkflowTask::class)->find($taskId);
        self::assertNotNull($escalatedTask);
        self::assertTrue($escalatedTask->isEscalated());
        self::assertNotNull($escalatedTask->getEscalatedAt());

        // 4. Verify CaseEvent (Module 9 timeline)
        $events = $this->em->getRepository(CaseEvent::class)->findBy([
            'case' => $this->em->getReference(DossierCase::class, $caseId),
            'action' => 'WORKFLOW_ESCALATED',
        ]);
        self::assertNotEmpty($events, 'Un événement de timeline WORKFLOW_ESCALATED doit être créé');
        $event = $events[0];
        self::assertSame(CaseEventType::WorkflowEscalated, $event->getEventType());
        self::assertStringContainsString('Escalade pour dépassement de délai', $event->getLabel());

        // 5. Verify WorkflowHistory
        $histories = $this->em->getRepository(WorkflowHistory::class)->findBy([
            'instance' => $escalatedTask->getInstance(),
            'action' => WorkflowAction::Escalate,
        ]);
        self::assertNotEmpty($histories, 'Un historique de workflow avec l\'action Escalate doit être créé');

        // 6. Verify Notifications were created for agent and supervisor
        $agentNotifs = $this->em->getRepository(Notification::class)->findBy([
            'user' => $this->em->getReference(User::class, $agentUser->getId()),
            'type' => 'delay_alert',
        ]);
        self::assertNotEmpty($agentNotifs, 'L\'agent assignataire doit recevoir une notification d\'alerte de délai');

        $supNotifs = $this->em->getRepository(Notification::class)->findBy([
            'user' => $this->em->getReference(User::class, $superiorUser->getId()),
            'type' => 'delay_alert',
        ]);
        self::assertNotEmpty($supNotifs, 'Le supérieur hiérarchique doit recevoir une notification d\'escalade');

        $notifsCountBefore = count($agentNotifs);

        // 7. Verify Idempotency: Running again should not create duplicate notifications or escalations
        $commandTester->execute(['--case-ref' => $caseRef, '--force' => true]);
        self::assertSame(0, $commandTester->getStatusCode());

        $this->em->clear();
        $agentNotifsAfter = $this->em->getRepository(Notification::class)->findBy([
            'user' => $this->em->getReference(User::class, $agentUser->getId()),
            'type' => 'delay_alert',
        ]);
        self::assertSame($notifsCountBefore, count($agentNotifsAfter), 'La commande doit être idempotente pour les notifications du même jour');
    }
}
