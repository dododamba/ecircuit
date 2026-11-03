<?php

namespace App\Tests\Service;

use App\Entity\AccessPermission;
use App\Entity\AccessRole;
use App\Entity\DossierCase;
use App\Entity\OrganizationUnit;
use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use App\Entity\WorkflowTransition;
use App\Enum\AccountStatus;
use App\Enum\EntryChannel;
use App\Enum\StructureType;
use App\Enum\WorkflowAction;
use App\Enum\WorkflowInstanceStatus;
use App\Exception\InvalidWorkflowTransitionException;
use App\Exception\WorkflowAuthorizationException;
use App\Exception\WorkflowException;
use App\Service\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class WorkflowServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private WorkflowService $workflowService;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->workflowService = $container->get(WorkflowService::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
    }

    private function getOrCreateUser(string $email, string $roleCode, ?string $structureCode = null): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $user = new User($email, 'Test', $roleCode);
            $user->setPassword($this->passwordHasher->hashPassword($user, 'Password123!'));
            $user->setStatus(AccountStatus::Active);
            $this->em->persist($user);
        }

        $role = $this->em->getRepository(AccessRole::class)->findOneBy(['code' => $roleCode]);
        if ($role && !$user->hasAccessRole($roleCode)) {
            $user->addAccessRole($role);
        }

        if (($roleCode === 'AGENT_CABINET' || $roleCode === 'SECRETAIRE_GENERAL') && $role) {
            $permClose = $this->em->getRepository(AccessPermission::class)->findOneBy(['code' => 'DOSSIER_CLOSE']);
            if ($permClose && !$role->hasPermission('DOSSIER_CLOSE')) {
                $role->grant($permClose);
            }
        }

        if ($structureCode) {
            $unit = $this->em->getRepository(OrganizationUnit::class)->findOneBy(['code' => $structureCode]);
            if ($unit) {
                $user->setOrganizationUnit($unit);
            }
        }

        $this->em->flush();

        return $user;
    }

    private function createDossierCase(string $suffix = ''): DossierCase
    {
        $unique = mt_rand(100000, 999999);
        $case = new DossierCase(
            sprintf('DOS-WF-%d-%s', $unique, $suffix),
            'Dossier de test workflow ' . $suffix,
            EntryChannel::Cabinet,
            new \DateTimeImmutable()
        );

        $cabinet = $this->em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'CABINET']);
        if ($cabinet) {
            $case->setCurrentStructure($cabinet);
        }

        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    /**
     * Test 1 : Cabinet → Ministre
     */
    public function testCabinetToMinistre(): void
    {
        $agentCabinet = $this->getOrCreateUser('agent_cab@mci.test', 'AGENT_CABINET', 'CABINET');
        $case = $this->createDossierCase('T1');

        $instance = $this->workflowService->start($case, startedBy: $agentCabinet);
        self::assertSame('CABINET_RECEPTION', $instance->getCurrentStep()->getCode());

        $history = $this->workflowService->submit($case, $agentCabinet, 'Soumission initiale pour avis du Ministre');

        self::assertSame('MINISTRE_REVIEW', $case->getWorkflowInstance()->getCurrentStep()->getCode());
        self::assertSame(WorkflowAction::Submit, $history->getAction());
        self::assertSame('SUBMITTED', $case->getStatusCode());
    }

    /**
     * Test 2 : Ministre → SG
     */
    public function testMinistreToSg(): void
    {
        $agentCabinet = $this->getOrCreateUser('agent_cab@mci.test', 'AGENT_CABINET', 'CABINET');
        $ministre = $this->getOrCreateUser('ministre@mci.test', 'MINISTRE', 'MINISTERE');
        $case = $this->createDossierCase('T2');

        $this->workflowService->start($case, startedBy: $agentCabinet);
        $this->workflowService->submit($case, $agentCabinet, 'Soumission');

        // Ministre annote puis transmet au SG
        $this->workflowService->annotate($case, $ministre, 'Instructions particulières : traiter avec diligence');
        self::assertSame('MINISTRE_REVIEW', $case->getWorkflowInstance()->getCurrentStep()->getCode());

        $sgUnit = $this->em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'SECRETARIAT_GENERAL']);
        $history = $this->workflowService->forward($case, $ministre, $sgUnit, 'Transmission pour orientation');

        self::assertSame('SG_REVIEW', $case->getWorkflowInstance()->getCurrentStep()->getCode());
        self::assertSame(WorkflowAction::Forward, $history->getAction());
        self::assertSame('FORWARDED', $case->getStatusCode());
    }

    /**
     * Test 3 : Ministre → Direction = REFUS (RÈGLE ABSOLUE RB-005)
     */
    public function testMinistreToDirectionRefusedRuleRb005(): void
    {
        $agentCabinet = $this->getOrCreateUser('agent_cab@mci.test', 'AGENT_CABINET', 'CABINET');
        $ministre = $this->getOrCreateUser('ministre@mci.test', 'MINISTRE', 'MINISTERE');
        $directionUnit = $this->em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DIRECTION_COMMERCE_INTERIEUR']);
        $case = $this->createDossierCase('T3');

        $this->workflowService->start($case, startedBy: $agentCabinet);
        $this->workflowService->submit($case, $agentCabinet, 'Soumission');

        // Le ministre tente de contourner le Secrétaire Général en envoyant directement à la direction technique
        $this->expectException(InvalidWorkflowTransitionException::class);
        $this->expectExceptionMessage('RB-005');

        $this->workflowService->forward($case, $ministre, $directionUnit, 'Tentative de contournement du SG');
    }

    /**
     * Test 4 : SG → DG
     */
    public function testSgToDg(): void
    {
        $agentCabinet = $this->getOrCreateUser('agent_cab@mci.test', 'AGENT_CABINET', 'CABINET');
        $ministre = $this->getOrCreateUser('ministre@mci.test', 'MINISTRE', 'MINISTERE');
        $sgUser = $this->getOrCreateUser('sg@mci.test', 'SECRETAIRE_GENERAL', 'SECRETARIAT_GENERAL');
        $dgUnit = $this->em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DG_COMMERCE']);
        $case = $this->createDossierCase('T4');

        $this->workflowService->start($case, startedBy: $agentCabinet);
        $this->workflowService->submit($case, $agentCabinet);
        $this->workflowService->forward($case, $ministre);

        // SG transmet à la DG Commerce
        $history = $this->workflowService->forward($case, $sgUser, $dgUnit, 'Pour suite à donner par la DG');

        self::assertSame('DG_REVIEW', $case->getWorkflowInstance()->getCurrentStep()->getCode());
        self::assertSame('DG_COMMERCE', $case->getCurrentStructure()?->getCode());
    }

    /**
     * Test 5 : DG → Direction
     */
    public function testDgToDirection(): void
    {
        $agentCabinet = $this->getOrCreateUser('agent_cab@mci.test', 'AGENT_CABINET', 'CABINET');
        $ministre = $this->getOrCreateUser('ministre@mci.test', 'MINISTRE', 'MINISTERE');
        $sgUser = $this->getOrCreateUser('sg@mci.test', 'SECRETAIRE_GENERAL', 'SECRETARIAT_GENERAL');
        $dgUser = $this->getOrCreateUser('dg_com@mci.test', 'DIRECTEUR_GENERAL', 'DG_COMMERCE');
        $dirUnit = $this->em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DIRECTION_COMMERCE_INTERIEUR']);
        $case = $this->createDossierCase('T5');

        $this->workflowService->start($case, startedBy: $agentCabinet);
        $this->workflowService->submit($case, $agentCabinet);
        $this->workflowService->forward($case, $ministre);
        $this->workflowService->forward($case, $sgUser);

        // DG transmet à la Direction
        $history = $this->workflowService->forward($case, $dgUser, $dirUnit, 'Pour affectation et instruction');

        self::assertSame('DIRECTION_PROCESSING', $case->getWorkflowInstance()->getCurrentStep()->getCode());
        self::assertSame('DIRECTION_COMMERCE_INTERIEUR', $case->getCurrentStructure()?->getCode());
    }

    /**
     * Test 6 : Direction → Agent
     */
    public function testDirectionToAgent(): void
    {
        $agentCabinet = $this->getOrCreateUser('agent_cab@mci.test', 'AGENT_CABINET', 'CABINET');
        $ministre = $this->getOrCreateUser('ministre@mci.test', 'MINISTRE', 'MINISTERE');
        $sgUser = $this->getOrCreateUser('sg@mci.test', 'SECRETAIRE_GENERAL', 'SECRETARIAT_GENERAL');
        $dgUser = $this->getOrCreateUser('dg_com@mci.test', 'DIRECTEUR_GENERAL', 'DG_COMMERCE');
        $respDir = $this->getOrCreateUser('resp_dir@mci.test', 'RESPONSABLE_DIRECTION', 'DIRECTION_COMMERCE_INTERIEUR');
        $agentTrait = $this->getOrCreateUser('agent_trait@mci.test', 'AGENT_TRAITEMENT', 'SERVICE_REGLEMENTATION');
        $case = $this->createDossierCase('T6');

        $this->workflowService->start($case, startedBy: $agentCabinet);
        $this->workflowService->submit($case, $agentCabinet);
        $this->workflowService->forward($case, $ministre);
        $this->workflowService->forward($case, $sgUser);
        $this->workflowService->forward($case, $dgUser);

        // Direction affecte à l'agent instructeur
        $this->workflowService->assign($case, $respDir, $agentTrait, instructions: 'Préparer la note technique');

        self::assertSame('AGENT_PROCESSING', $case->getWorkflowInstance()->getCurrentStep()->getCode());
        self::assertSame($agentTrait->getId(), $case->getCurrentAssignee()?->getId());
        self::assertSame('ASSIGNED', $case->getStatusCode());

        $activeTask = $case->getWorkflowInstance()->getActiveTask();
        self::assertNotNull($activeTask);
        self::assertSame($agentTrait->getId(), $activeTask->getTargetUser()?->getId());
    }

    /**
     * Test 7 : Agent → Responsable
     */
    public function testAgentToResponsable(): void
    {
        $agentCabinet = $this->getOrCreateUser('agent_cab@mci.test', 'AGENT_CABINET', 'CABINET');
        $ministre = $this->getOrCreateUser('ministre@mci.test', 'MINISTRE', 'MINISTERE');
        $sgUser = $this->getOrCreateUser('sg@mci.test', 'SECRETAIRE_GENERAL', 'SECRETARIAT_GENERAL');
        $dgUser = $this->getOrCreateUser('dg_com@mci.test', 'DIRECTEUR_GENERAL', 'DG_COMMERCE');
        $respDir = $this->getOrCreateUser('resp_dir@mci.test', 'RESPONSABLE_DIRECTION', 'DIRECTION_COMMERCE_INTERIEUR');
        $agentTrait = $this->getOrCreateUser('agent_trait@mci.test', 'AGENT_TRAITEMENT', 'SERVICE_REGLEMENTATION');
        $case = $this->createDossierCase('T7');

        $this->workflowService->start($case, startedBy: $agentCabinet);
        $this->workflowService->submit($case, $agentCabinet);
        $this->workflowService->forward($case, $ministre);
        $this->workflowService->forward($case, $sgUser);
        $this->workflowService->forward($case, $dgUser);
        $this->workflowService->assign($case, $respDir, $agentTrait);

        // L'agent prend en charge puis transmet les conclusions
        $this->workflowService->takeInCharge($case, $agentTrait);
        self::assertSame('IN_PROGRESS', $case->getStatusCode());

        $this->workflowService->process($case, $agentTrait, 'Analyse achevée, projet de courrier joint');

        self::assertSame('DIRECTION_VALIDATION', $case->getWorkflowInstance()->getCurrentStep()->getCode());
        self::assertSame('IN_ANALYSIS', $case->getStatusCode());
    }

    /**
     * Test 8 : Retour contrôlé
     */
    public function testReturnControlled(): void
    {
        $agentCabinet = $this->getOrCreateUser('agent_cab@mci.test', 'AGENT_CABINET', 'CABINET');
        $ministre = $this->getOrCreateUser('ministre@mci.test', 'MINISTRE', 'MINISTERE');
        $sgUser = $this->getOrCreateUser('sg@mci.test', 'SECRETAIRE_GENERAL', 'SECRETARIAT_GENERAL');
        $dgUser = $this->getOrCreateUser('dg_com@mci.test', 'DIRECTEUR_GENERAL', 'DG_COMMERCE');
        $respDir = $this->getOrCreateUser('resp_dir@mci.test', 'RESPONSABLE_DIRECTION', 'DIRECTION_COMMERCE_INTERIEUR');
        $agentTrait = $this->getOrCreateUser('agent_trait@mci.test', 'AGENT_TRAITEMENT', 'SERVICE_REGLEMENTATION');
        $case = $this->createDossierCase('T8');

        $this->workflowService->start($case, startedBy: $agentCabinet);
        $this->workflowService->submit($case, $agentCabinet);
        $this->workflowService->forward($case, $ministre);
        $this->workflowService->forward($case, $sgUser);
        $this->workflowService->forward($case, $dgUser);
        $this->workflowService->assign($case, $respDir, $agentTrait);
        $this->workflowService->process($case, $agentTrait, 'Premier jet');

        // Le responsable retourne à l'agent avec motif
        $history = $this->workflowService->return($case, $respDir, 'Pièces justificatives manquantes', 'Compléter le dossier');

        self::assertSame('AGENT_PROCESSING', $case->getWorkflowInstance()->getCurrentStep()->getCode());
        self::assertSame('RETURNED', $case->getStatusCode());
        self::assertStringContainsString('Pièces justificatives manquantes', $history->getComment());
    }

    /**
     * Test 9 : Rejet justifié
     */
    public function testRejectJustified(): void
    {
        $agentCabinet = $this->getOrCreateUser('agent_cab@mci.test', 'AGENT_CABINET', 'CABINET');
        $ministre = $this->getOrCreateUser('ministre@mci.test', 'MINISTRE', 'MINISTERE');
        $sgUser = $this->getOrCreateUser('sg@mci.test', 'SECRETAIRE_GENERAL', 'SECRETARIAT_GENERAL');
        $dgUser = $this->getOrCreateUser('dg_com@mci.test', 'DIRECTEUR_GENERAL', 'DG_COMMERCE');
        $respDir = $this->getOrCreateUser('resp_dir@mci.test', 'RESPONSABLE_DIRECTION', 'DIRECTION_COMMERCE_INTERIEUR');
        $agentTrait = $this->getOrCreateUser('agent_trait@mci.test', 'AGENT_TRAITEMENT', 'SERVICE_REGLEMENTATION');
        $case = $this->createDossierCase('T9');

        $this->workflowService->start($case, startedBy: $agentCabinet);
        $this->workflowService->submit($case, $agentCabinet);
        $this->workflowService->forward($case, $ministre);
        $this->workflowService->forward($case, $sgUser);
        $this->workflowService->forward($case, $dgUser);
        $this->workflowService->assign($case, $respDir, $agentTrait);
        $this->workflowService->process($case, $agentTrait, 'Conclusions');

        // 9.1 Rejet sans justification = REFUS
        try {
            $this->workflowService->reject($case, $respDir, '');
            self::fail('Un rejet sans commentaire aurait dû lever une exception');
        } catch (InvalidWorkflowTransitionException $e) {
            self::assertStringContainsString('motif de rejet', $e->getMessage());
        }

        // 9.2 Rejet avec justification valide
        $history = $this->workflowService->reject($case, $respDir, 'Proposition irrecevable au regard de la réglementation');
        self::assertSame(WorkflowAction::Reject, $history->getAction());
        self::assertSame('REJECTED', $case->getStatusCode());
        self::assertSame('AGENT_PROCESSING', $case->getWorkflowInstance()->getCurrentStep()->getCode());
    }

    /**
     * Test 10 : Validation en cascade hiérarchique
     */
    public function testValidationCascade(): void
    {
        $agentCabinet = $this->getOrCreateUser('agent_cab@mci.test', 'AGENT_CABINET', 'CABINET');
        $ministre = $this->getOrCreateUser('ministre@mci.test', 'MINISTRE', 'MINISTERE');
        $sgUser = $this->getOrCreateUser('sg@mci.test', 'SECRETAIRE_GENERAL', 'SECRETARIAT_GENERAL');
        $dgUser = $this->getOrCreateUser('dg_com@mci.test', 'DIRECTEUR_GENERAL', 'DG_COMMERCE');
        $respDir = $this->getOrCreateUser('resp_dir@mci.test', 'RESPONSABLE_DIRECTION', 'DIRECTION_COMMERCE_INTERIEUR');
        $agentTrait = $this->getOrCreateUser('agent_trait@mci.test', 'AGENT_TRAITEMENT', 'SERVICE_REGLEMENTATION');
        $case = $this->createDossierCase('T10');

        $this->workflowService->start($case, startedBy: $agentCabinet);
        $this->workflowService->submit($case, $agentCabinet);
        $this->workflowService->forward($case, $ministre);
        $this->workflowService->forward($case, $sgUser);
        $this->workflowService->forward($case, $dgUser);
        $this->workflowService->assign($case, $respDir, $agentTrait);
        $this->workflowService->process($case, $agentTrait, 'Analyse achevée');

        // Validation Direction -> DG
        $this->workflowService->validate($case, $respDir, 'Validé par Direction');
        self::assertSame('DG_VALIDATION', $case->getWorkflowInstance()->getCurrentStep()->getCode());

        // Validation DG -> SG
        $this->workflowService->validate($case, $dgUser, 'Validé par DG');
        self::assertSame('SG_VALIDATION', $case->getWorkflowInstance()->getCurrentStep()->getCode());

        // Validation SG -> Ministre Signature
        $this->workflowService->validate($case, $sgUser, 'Validé par SG, soumis pour signature');
        self::assertSame('MINISTRE_SIGNATURE', $case->getWorkflowInstance()->getCurrentStep()->getCode());

        // Signature Ministre -> Sortie Cabinet
        $this->workflowService->validate($case, $ministre, 'Signé par le Ministre');
        self::assertSame('CABINET_RELEASE', $case->getWorkflowInstance()->getCurrentStep()->getCode());
    }

    /**
     * Test 11 : Clôture et sortie du circuit
     */
    public function testCloseAndRelease(): void
    {
        $agentCabinet = $this->getOrCreateUser('agent_cab@mci.test', 'AGENT_CABINET', 'CABINET');
        $ministre = $this->getOrCreateUser('ministre@mci.test', 'MINISTRE', 'MINISTERE');
        $sgUser = $this->getOrCreateUser('sg@mci.test', 'SECRETAIRE_GENERAL', 'SECRETARIAT_GENERAL');
        $dgUser = $this->getOrCreateUser('dg_com@mci.test', 'DIRECTEUR_GENERAL', 'DG_COMMERCE');
        $respDir = $this->getOrCreateUser('resp_dir@mci.test', 'RESPONSABLE_DIRECTION', 'DIRECTION_COMMERCE_INTERIEUR');
        $agentTrait = $this->getOrCreateUser('agent_trait@mci.test', 'AGENT_TRAITEMENT', 'SERVICE_REGLEMENTATION');
        $case = $this->createDossierCase('T11');

        $this->workflowService->start($case, startedBy: $agentCabinet);
        $this->workflowService->submit($case, $agentCabinet);
        $this->workflowService->forward($case, $ministre);
        $this->workflowService->forward($case, $sgUser);
        $this->workflowService->forward($case, $dgUser);
        $this->workflowService->assign($case, $respDir, $agentTrait);
        $this->workflowService->process($case, $agentTrait);
        $this->workflowService->validate($case, $respDir);
        $this->workflowService->validate($case, $dgUser);
        $this->workflowService->validate($case, $sgUser);
        $this->workflowService->validate($case, $ministre);

        self::assertSame('CABINET_RELEASE', $case->getWorkflowInstance()->getCurrentStep()->getCode());

        // Cabinet enregistre la sortie et clôture
        $this->workflowService->release($case, $agentCabinet, 'Courrier transmis au destinataire et archivé');

        self::assertSame('ARCHIVED', $case->getWorkflowInstance()->getCurrentStep()->getCode());
        self::assertTrue($case->getWorkflowInstance()->isCompleted());
        self::assertSame('RELEASED', $case->getStatusCode());
    }

    /**
     * Test 12 : Utilisateur non autorisé (Habilitation insuffisante)
     */
    public function testUnauthorizedUserRefusal(): void
    {
        $agentCabinet = $this->getOrCreateUser('agent_cab@mci.test', 'AGENT_CABINET', 'CABINET');
        $agentTrait = $this->getOrCreateUser('agent_trait@mci.test', 'AGENT_TRAITEMENT', 'SERVICE_REGLEMENTATION');
        $case = $this->createDossierCase('T12');

        $this->workflowService->start($case, startedBy: $agentCabinet);

        // L'agent de traitement ne peut pas exécuter SUBMIT depuis CABINET_RECEPTION (rôle AGENT_CABINET requis)
        $this->expectException(WorkflowAuthorizationException::class);
        $this->workflowService->submit($case, $agentTrait);
    }

    /**
     * Test 13 : Mauvaise organisation (Hors périmètre organisationnel)
     */
    public function testWrongOrganizationRefusal(): void
    {
        $agentCabinet = $this->getOrCreateUser('agent_cab@mci.test', 'AGENT_CABINET', 'CABINET');
        $case = $this->createDossierCase('T13');

        $this->workflowService->start($case, startedBy: $agentCabinet);

        // Utilisateur ayant le bon rôle AGENT_CABINET mais rattaché à une direction technique extérieure
        $externalUser = $this->getOrCreateUser('external_agent@mci.test', 'AGENT_CABINET', 'DIRECTION_COMMERCE_INTERIEUR');

        $this->expectException(WorkflowAuthorizationException::class);
        $this->workflowService->submit($case, $externalUser);
    }

    /**
     * Test 14 : Mauvaise transition (Action non permise à l'étape actuelle)
     */
    public function testBadTransitionRefusal(): void
    {
        $agentCabinet = $this->getOrCreateUser('agent_cab@mci.test', 'AGENT_CABINET', 'CABINET');
        $case = $this->createDossierCase('T14');

        $this->workflowService->start($case, startedBy: $agentCabinet);

        // À l'étape initiale CABINET_RECEPTION, l'action VALIDATE n'existe pas
        $this->expectException(InvalidWorkflowTransitionException::class);
        $this->workflowService->validate($case, $agentCabinet);
    }

    /**
     * Test 15 : Transition répétée ou étape dépassée
     */
    public function testRepeatedTransitionRefusal(): void
    {
        $agentCabinet = $this->getOrCreateUser('agent_cab@mci.test', 'AGENT_CABINET', 'CABINET');
        $case = $this->createDossierCase('T15');

        $this->workflowService->start($case, startedBy: $agentCabinet);
        $this->workflowService->submit($case, $agentCabinet);

        // Le dossier est maintenant à MINISTRE_REVIEW. Réexécuter SUBMIT doit être refusé
        $this->expectException(InvalidWorkflowTransitionException::class);
        $this->workflowService->submit($case, $agentCabinet);
    }

    /**
     * Test 16 : Dossier déjà clôturé
     */
    public function testClosedDossierRefusal(): void
    {
        $agentCabinet = $this->getOrCreateUser('agent_cab@mci.test', 'AGENT_CABINET', 'CABINET');
        $case = $this->createDossierCase('T16');
        $this->workflowService->start($case, startedBy: $agentCabinet);

        $case->close($agentCabinet);
        $this->em->flush();

        $this->expectException(InvalidWorkflowTransitionException::class);
        $this->expectExceptionMessage('déjà clôturé');

        $this->workflowService->submit($case, $agentCabinet);
    }

    /**
     * Test 17 : S3.03 — Circuit direct Secrétariat Général (WORKFLOW_SG_STANDARD)
     */
    public function testWorkflowSgDirect(): void
    {
        $sgUser = $this->getOrCreateUser('sg_workflow@mci.test', 'SECRETAIRE_GENERAL', 'SECRETARIAT_GENERAL');
        $dgUser = $this->getOrCreateUser('dg_workflow@mci.test', 'DIRECTEUR_GENERAL', 'DG_COMMERCE');
        $dirUser = $this->getOrCreateUser('dir_workflow@mci.test', 'RESPONSABLE_DIRECTION', 'DIRECTION_COMMERCE_INTERIEUR');
        $agentUser = $this->getOrCreateUser('agent_workflow@mci.test', 'AGENT_TRAITEMENT', 'SERVICE_REGLEMENTATION');

        $unique = mt_rand(100000, 999999);
        $case = new DossierCase(
            sprintf('DOS-SG-%d', $unique),
            'Dossier transmis directement au SG',
            EntryChannel::SecretariatGeneral,
            new \DateTimeImmutable()
        );
        $sgUnit = $this->em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'SECRETARIAT_GENERAL']);
        $case->setCurrentStructure($sgUnit);
        $this->em->persist($case);
        $this->em->flush();

        // Démarrage du workflow SG automatique
        $instance = $this->workflowService->start($case, startedBy: $sgUser);
        self::assertSame('WORKFLOW_SG_STANDARD', $instance->getWorkflow()->getCode());
        self::assertSame('SG_RECEPTION', $instance->getCurrentStep()->getCode());

        // 1. SG Soumet pour revue
        $this->workflowService->submit($case, $sgUser, 'Examen préliminaire SG');
        self::assertSame('SG_REVIEW', $instance->getCurrentStep()->getCode());

        // 2. SG Oriente vers DG Commerce
        $dgCommerce = $this->em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DG_COMMERCE']);
        $this->workflowService->orient($case, $sgUser, $dgCommerce, 'Orientation DG Commerce');
        self::assertSame('DG_REVIEW', $instance->getCurrentStep()->getCode());
        self::assertSame('DG_COMMERCE', $case->getCurrentStructure()->getCode());

        // 3. DG Transmet à Direction Commerce Intérieur
        $dci = $this->em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DIRECTION_COMMERCE_INTERIEUR']);
        $this->workflowService->forward($case, $dgUser, $dci, 'Transmission DCI');
        self::assertSame('DIRECTION_PROCESSING', $instance->getCurrentStep()->getCode());

        // 4. Direction Affecte à l'Agent instructeur
        $this->workflowService->assign($case, $dirUser, $agentUser, null, 'Instruction sous 48h');
        self::assertSame('AGENT_PROCESSING', $instance->getCurrentStep()->getCode());
        self::assertSame($agentUser->getId(), $case->getCurrentAssignee()?->getId());

        // 5. Agent prend en charge et traite
        $this->workflowService->takeInCharge($case, $agentUser);
        self::assertSame('IN_PROGRESS', $case->getStatusCode());

        $this->workflowService->process($case, $agentUser, 'Rapport d\'instruction finalisé');
        self::assertSame('DIRECTION_VALIDATION', $instance->getCurrentStep()->getCode());

        // 6. Validation hiérarchique Direction -> DG -> SG
        $this->workflowService->validate($case, $dirUser, 'Avis favorable Direction');
        self::assertSame('DG_VALIDATION', $instance->getCurrentStep()->getCode());

        $this->workflowService->validate($case, $dgUser, 'Validation DG');
        self::assertSame('SG_VALIDATION', $instance->getCurrentStep()->getCode());

        // 7. Sortie & Clôture SG
        $this->workflowService->forward($case, $sgUser, null, 'Préparation de sortie');
        self::assertSame('SG_RELEASE', $instance->getCurrentStep()->getCode());

        $this->workflowService->release($case, $sgUser, 'Sortie officielle et notification usager');
        self::assertSame('ARCHIVED', $instance->getCurrentStep()->getCode());
        self::assertTrue($instance->isCompleted());
    }

    /**
     * Test 18 : S3.11 — Synchronisation dynamique Circuit Administratif (Back-office) -> Moteur Workflow
     */
    public function testCircuitWorkflowSyncAndBackofficeModification(): void
    {
        /** @var \App\Service\CircuitWorkflowSyncService $syncService */
        $syncService = static::getContainer()->get(\App\Service\CircuitWorkflowSyncService::class);

        $dci = $this->em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DIRECTION_COMMERCE_INTERIEUR']);
        $ddi = $this->em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DIRECTION_DEVELOPPEMENT_INDUSTRIEL']);

        $uniqueCode = 'CIRCUIT_CUSTOM_' . mt_rand(1000, 9999);
        $circuit = new \App\Entity\AdministrativeCircuit(
            $uniqueCode,
            'Circuit sur mesure Démat',
            'Description du circuit test'
        );
        new \App\Entity\CircuitStep($circuit, $dci, 1, 'Étape 1 DCI', 24);
        new \App\Entity\CircuitStep($circuit, $ddi, 2, 'Étape 2 DDI', 72);

        $this->em->persist($circuit);
        $this->em->flush();

        // Synchronisation du circuit vers le moteur de workflow
        $workflow = $syncService->syncCircuitToWorkflow($circuit);
        self::assertNotNull($workflow);
        self::assertSame($syncService->resolveWorkflowCode($circuit), $workflow->getCode());
        self::assertGreaterThanOrEqual(3, $workflow->getSteps()->count()); // Étape 1 + Étape 2 + ARCHIVED

        $step1 = $workflow->getSteps()->first();
        self::assertSame(24, $step1->getStandardDurationHours());
        self::assertSame($dci->getId(), $step1->getTargetStructure()?->getId());

        // Démarrage d'un dossier utilisant ce circuit
        $case = new DossierCase('DOS-CUSTOM-' . uniqid(), 'Dossier circuit personnalisé', EntryChannel::Cabinet, new \DateTimeImmutable());
        $case->setCircuit($circuit);
        $this->em->persist($case);
        $this->em->flush();

        $admin = $this->getOrCreateUser('admin_sync@mci.test', 'ADMINISTRATEUR', 'MINISTERE');
        $instance = $this->workflowService->start($case, startedBy: $admin);

        self::assertSame($workflow->getId(), $instance->getWorkflow()->getId());
        self::assertSame($step1->getId(), $instance->getCurrentStep()->getId());

        // Mise à jour du délai dans le circuit (Back-office) sans casser l'instance existante
        $circuit->getSteps()->first()->setStandardDurationHours(36);
        $syncService->syncCircuitToWorkflow($circuit);

        $updatedStep1 = $this->em->getRepository(WorkflowStep::class)->find($step1->getId());
        self::assertSame(36, $updatedStep1->getStandardDurationHours());
        self::assertFalse($instance->isCompleted());
    }

    /**
     * Test 19 : S3.12 — Circuit Délégation Provinciale (WORKFLOW_PROVINCIAL) et isolation inter-provinces
     */
    public function testWorkflowProvincialAndIsolation(): void
    {
        $delEst = $this->em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DELEGATION_PROV_EST']);
        $delOuest = $this->em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DELEGATION_PROV_OUEST']);

        self::assertNotNull($delEst);
        self::assertNotNull($delOuest);

        $agentProvEst = $this->getOrCreateUser('agent.est@mci.test', 'AGENT_TRAITEMENT', 'DELEGATION_PROV_EST');
        $delegueEst = $this->getOrCreateUser('delegue.est@mci.test', 'RESPONSABLE_DIRECTION', 'DELEGATION_PROV_EST');
        $agentProvOuest = $this->getOrCreateUser('agent.ouest@mci.test', 'AGENT_TRAITEMENT', 'DELEGATION_PROV_OUEST');

        $unique = mt_rand(100000, 999999);
        $caseEst = new DossierCase(
            sprintf('DOS-PROV-EST-%d', $unique),
            'Dossier enregistré en province Est',
            EntryChannel::SecretariatGeneral,
            new \DateTimeImmutable()
        );
        $caseEst->setCurrentStructure($delEst);
        $this->em->persist($caseEst);
        $this->em->flush();

        // 1. Démarrage automatique en WORKFLOW_PROVINCIAL
        $instance = $this->workflowService->start($caseEst, startedBy: $delegueEst);
        self::assertSame('WORKFLOW_PROVINCIAL', $instance->getWorkflow()->getCode());
        self::assertSame('PROVINCIAL_RECEPTION', $instance->getCurrentStep()->getCode());
        self::assertSame('DELEGATION_PROV_EST', $caseEst->getCurrentStructure()->getCode());

        // 2. Vérification isolation : l'agent de la province Ouest ne peut pas instruire le dossier de la province Est
        $this->expectException(WorkflowAuthorizationException::class);
        $this->workflowService->submit($caseEst, $agentProvOuest);
    }
}
