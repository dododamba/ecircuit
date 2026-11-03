<?php

namespace App\Tests\Controller;

use App\Entity\AccessPermission;
use App\Entity\AccessRole;
use App\Entity\AuditLog;
use App\Entity\CaseEvent;
use App\Entity\Document;
use App\Entity\DossierCase;
use App\Entity\OrganizationUnit;
use App\Entity\PasswordResetToken;
use App\Entity\ReferenceItem;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\AuditAction;
use App\Enum\AuditResult;
use App\Enum\CaseStatus;
use App\Enum\EntryChannel;
use App\Enum\ReferenceCategory;
use App\Enum\SenderType;
use App\Repository\AuditLogRepository;
use App\Service\AuditLogger;
use App\Service\IncomingMailService;
use App\Service\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuditAndSecurityLogTest extends WebTestCase
{
    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function getAuditRepository(): AuditLogRepository
    {
        return self::getContainer()->get(AuditLogRepository::class);
    }

    private function getAuditLogger(): AuditLogger
    {
        return self::getContainer()->get(AuditLogger::class);
    }

    private function getPasswordHasher(): UserPasswordHasherInterface
    {
        return self::getContainer()->get(UserPasswordHasherInterface::class);
    }

    private function getOrCreateUser(string $email, string $roleCode, ?string $structureCode = null): User
    {
        $em = $this->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $user = new User($email, 'Audit', $roleCode);
            $user->setPassword($this->getPasswordHasher()->hashPassword($user, 'PasswordSecret123!'));
            $user->setStatus(AccountStatus::Active);
            $em->persist($user);
        }

        $role = $em->getRepository(AccessRole::class)->findOneBy(['code' => $roleCode]);
        if ($role && !$user->hasAccessRole($roleCode)) {
            $user->addAccessRole($role);
        }

        if ($structureCode) {
            $unit = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => $structureCode]);
            if ($unit) {
                $user->setOrganizationUnit($unit);
            }
        }

        $em->flush();

        return $user;
    }

    /**
     * 18.1 — Tests unitaires : Sanitization des métadonnées (secrets, mots de passe, tokens, entités)
     * et mapping AuditAction / AuditResult / AuditLog.
     */
    public function testUnitMetadataSanitizationAndMapping(): void
    {
        self::bootKernel();
        $auditLogger = $this->getAuditLogger();
        $em = $this->getEntityManager();

        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'admin@mci.local']);
        self::assertNotNull($admin);

        $dirtyMetadata = [
            'username' => 'admin@mci.local',
            'password' => 'SuperSecretPassword123!',
            'plainPassword' => 'PlainTextSecret!',
            'password_confirmation' => 'SuperSecretPassword123!',
            'current_password' => 'OldPassword123!',
            'new_password' => 'NewPassword123!',
            'token' => 'reset_token_999999',
            'access_token' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.secret',
            'refresh_token' => 'ref_secret_token',
            'jwt' => 'eyJhbGciOiJIUzI1NiJ9',
            'api_secret' => 'mci_api_secret_key',
            'private_key' => '-----BEGIN PRIVATE KEY-----',
            'credentials' => ['secret' => 'hidden'],
            '_token' => 'csrf_token_value',
            'nested' => [
                'safe_field' => 'valeur_autorisee',
                'user_password' => 'do_not_log_me',
                'bearer_token' => 'secret_bearer',
                'entity_obj' => $admin,
            ],
            'Authorization' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.secret_header',
        ];

        $sanitized = $auditLogger->sanitizeMetadata($dirtyMetadata);

        // Vérifier qu'aucune clé interdite n'est présente
        self::assertArrayHasKey('username', $sanitized);
        self::assertSame('admin@mci.local', $sanitized['username']);
        self::assertArrayNotHasKey('password', $sanitized);
        self::assertArrayNotHasKey('plainPassword', $sanitized);
        self::assertArrayNotHasKey('password_confirmation', $sanitized);
        self::assertArrayNotHasKey('current_password', $sanitized);
        self::assertArrayNotHasKey('new_password', $sanitized);
        self::assertArrayNotHasKey('token', $sanitized);
        self::assertArrayNotHasKey('access_token', $sanitized);
        self::assertArrayNotHasKey('refresh_token', $sanitized);
        self::assertArrayNotHasKey('jwt', $sanitized);
        self::assertArrayNotHasKey('api_secret', $sanitized);
        self::assertArrayNotHasKey('private_key', $sanitized);
        self::assertArrayNotHasKey('credentials', $sanitized);
        self::assertArrayNotHasKey('_token', $sanitized);

        // Vérifier le sous-tableau imbriqué et la non-sérialisation d'entité complète
        self::assertArrayHasKey('nested', $sanitized);
        self::assertSame('valeur_autorisee', $sanitized['nested']['safe_field']);
        self::assertArrayNotHasKey('user_password', $sanitized['nested']);
        self::assertArrayNotHasKey('bearer_token', $sanitized['nested']);
        self::assertSame(['entity' => 'User', 'id' => (string) $admin->getId()], $sanitized['nested']['entity_obj']);

        // Vérifier la rédaction des valeurs de type Bearer JWT
        self::assertSame('[REDACTED]', $sanitized['Authorization']);

        // Tester la création directe via AuditLogger avec contexte HTTP simulé
        $request = Request::create('/api/dossiers/1770/transmit', 'POST', [], [], [], [
            'REMOTE_ADDR' => '10.10.20.45',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 MCI-Test-Agent',
            'HTTP_X_REQUEST_ID' => 'req-mci-unit-001',
        ]);
        $request->attributes->set('_route', 'workflow_case_transition');

        $log = $auditLogger->log(
            event: AuditAction::WorkflowForwarded,
            success: true,
            user: $admin,
            request: $request,
            context: $dirtyMetadata,
            entityType: 'DossierCase',
            entityId: '1770',
            result: AuditResult::Success,
            statusCode: 200
        );

        self::assertNotNull($log->getId());
        self::assertSame('WORKFLOW_FORWARDED', $log->getAction());
        self::assertSame('WORKFLOW_FORWARDED', $log->getEvent());
        self::assertSame('DossierCase', $log->getEntityType());
        self::assertSame('1770', $log->getEntityId());
        self::assertSame('10.10.20.45', $log->getIpAddress());
        self::assertSame('Mozilla/5.0 MCI-Test-Agent', $log->getUserAgent());
        self::assertSame('workflow_case_transition', $log->getRoute());
        self::assertSame('POST', $log->getHttpMethod());
        self::assertSame(200, $log->getStatusCode());
        self::assertSame('req-mci-unit-001', $log->getRequestId());
        self::assertSame('SUCCESS', $log->getResult());
        self::assertTrue($log->isSuccess());

        $persistedMeta = $log->getMetadata();
        self::assertArrayNotHasKey('password', $persistedMeta);
        self::assertArrayNotHasKey('plainPassword', $persistedMeta);
        self::assertArrayNotHasKey('token', $persistedMeta);
        self::assertArrayNotHasKey('jwt', $persistedMeta);
    }

    /**
     * 18.2 — Tests fonctionnels Authentification :
     * LOGIN_SUCCESS, LOGIN_FAILURE, LOGOUT, PASSWORD_CHANGED, PASSWORD_RESET.
     */
    public function testAuthenticationAuditEvents(): void
    {
        $client = static::createClient();
        $em = $this->getEntityManager();
        $auditRepo = $this->getAuditRepository();

        // 1. LOGIN_FAILURE
        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => 'admin@mci.local',
            '_password' => 'MotDePasseInvalide999!',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/login');

        $failureLogs = $auditRepo->findByFilters(['action' => AuditAction::LoginFailure->value], 5, 0);
        self::assertNotEmpty($failureLogs, 'LOGIN_FAILURE doit être journalisé.');
        $latestFail = $failureLogs[0];
        self::assertSame('LOGIN_FAILURE', $latestFail->getAction());
        self::assertSame('FAILURE', $latestFail->getResult());
        self::assertFalse($latestFail->isSuccess());
        self::assertArrayNotHasKey('password', $latestFail->getMetadata() ?? []);
        self::assertArrayNotHasKey('_password', $latestFail->getMetadata() ?? []);

        // 2. LOGIN_SUCCESS
        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => 'admin@mci.local',
            '_password' => 'TestAdminSecret123!',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/');

        $successLogs = $auditRepo->findByFilters(['action' => AuditAction::LoginSuccess->value], 5, 0);
        self::assertNotEmpty($successLogs, 'LOGIN_SUCCESS doit être journalisé.');
        $latestSuccess = $successLogs[0];
        self::assertSame('LOGIN_SUCCESS', $latestSuccess->getAction());
        self::assertSame('SUCCESS', $latestSuccess->getResult());
        self::assertTrue($latestSuccess->isSuccess());
        self::assertNotNull($latestSuccess->getUser());
        self::assertSame('admin@mci.local', $latestSuccess->getUser()->getEmail());

        // 3. PASSWORD_CHANGED (via /api/auth/change-password)
        $client->request(
            'POST',
            '/api/auth/change-password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'current_password' => 'TestAdminSecret123!',
                'new_password' => 'TestAdminSecret123!',
            ])
        );
        self::assertResponseIsSuccessful();

        $pwdChangedLogs = $auditRepo->findByFilters(['action' => AuditAction::PasswordChanged->value, 'result' => 'SUCCESS'], 5, 0);
        self::assertNotEmpty($pwdChangedLogs, 'PASSWORD_CHANGED doit être journalisé.');
        self::assertArrayNotHasKey('new_password', $pwdChangedLogs[0]->getMetadata() ?? []);
        self::assertArrayNotHasKey('current_password', $pwdChangedLogs[0]->getMetadata() ?? []);

        // 4. LOGOUT
        $csrfLogout = $client->getContainer()->get('security.csrf.token_manager')->getToken('logout')->getValue();
        $client->request('POST', '/logout', ['_token' => $csrfLogout]);

        $logoutLogs = $auditRepo->findByFilters(['action' => AuditAction::Logout->value], 5, 0);
        self::assertNotEmpty($logoutLogs, 'LOGOUT doit être journalisé.');
        self::assertSame('SUCCESS', $logoutLogs[0]->getResult());

        // 5. PASSWORD_RESET (demande + réinitialisation effective)
        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'admin@mci.local']);
        self::assertNotNull($admin);
        $rawToken = bin2hex(random_bytes(16));
        $resetToken = new PasswordResetToken(
            $admin,
            hash('sha256', $rawToken),
            new \DateTimeImmutable('+30 minutes')
        );
        $em->persist($resetToken);
        $em->flush();

        $crawler = $client->request('GET', '/reset-password/' . $rawToken);
        self::assertResponseIsSuccessful();
        $csrfReset = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/reset-password/' . $rawToken, [
            '_csrf_token' => $csrfReset,
            'password' => 'TestAdminSecret123!',
            'password_confirm' => 'TestAdminSecret123!',
        ]);
        self::assertResponseRedirects('/login');

        $resetLogs = $auditRepo->findByFilters(['action' => AuditAction::PasswordReset->value, 'result' => 'SUCCESS'], 5, 0);
        self::assertNotEmpty($resetLogs, 'PASSWORD_RESET doit être journalisé.');
        self::assertSame('User', $resetLogs[0]->getEntityType());
        self::assertSame((string) $admin->getId(), $resetLogs[0]->getEntityId());
        self::assertArrayNotHasKey('password', $resetLogs[0]->getMetadata() ?? []);
    }

    /**
     * 18.2 — Tests fonctionnels Utilisateurs & IAM :
     * USER_CREATED, USER_UPDATED, USER_DISABLED, USER_ENABLED, USER_ROLE_CHANGED, USER_PERMISSION_CHANGED.
     */
    public function testUserAndIamAuditEvents(): void
    {
        $client = static::createClient();
        $em = $this->getEntityManager();
        $auditRepo = $this->getAuditRepository();

        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'admin@mci.local']);
        self::assertNotNull($admin);
        $client->loginUser($admin);

        // 1. USER_CREATED via API
        $uniqueEmail = 'agent.audit.' . uniqid() . '@mci.local';
        $client->request(
            'POST',
            '/api/users',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => $uniqueEmail,
                'first_name' => 'Jean',
                'last_name' => 'AuditTest',
                'password' => 'InitialPass123!',
                'roles' => ['AGENT_COURRIER'],
            ])
        );
        self::assertResponseStatusCodeSame(201);
        $responseData = json_decode((string) $client->getResponse()->getContent(), true);
        $createdUserId = (string) $responseData['id'];

        $createdLogs = $auditRepo->findByFilters([
            'action' => AuditAction::UserCreated->value,
            'entity_type' => 'User',
            'entity_id' => $createdUserId,
        ]);
        self::assertNotEmpty($createdLogs, 'USER_CREATED doit être journalisé.');
        self::assertArrayNotHasKey('password', $createdLogs[0]->getMetadata() ?? []);

        // 2. USER_UPDATED + USER_ROLE_CHANGED via API PATCH /api/users/{id}
        $client->request(
            'PATCH',
            '/api/users/' . $createdUserId,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'first_name' => 'Jean-Modifié',
                'roles' => ['AGENT_CABINET'],
            ])
        );
        self::assertResponseIsSuccessful();

        $updatedLogs = $auditRepo->findByFilters([
            'action' => AuditAction::UserUpdated->value,
            'entity_type' => 'User',
            'entity_id' => $createdUserId,
        ]);
        self::assertNotEmpty($updatedLogs, 'USER_UPDATED doit être journalisé.');

        $roleChangedLogs = $auditRepo->findByFilters([
            'action' => AuditAction::UserRoleChanged->value,
            'entity_type' => 'User',
            'entity_id' => $createdUserId,
        ]);
        self::assertNotEmpty($roleChangedLogs, 'USER_ROLE_CHANGED doit être journalisé.');
        self::assertSame(['AGENT_COURRIER'], $roleChangedLogs[0]->getMetadata()['previous_roles'] ?? []);
        self::assertSame(['AGENT_CABINET'], $roleChangedLogs[0]->getMetadata()['new_roles'] ?? []);

        // 3. USER_DISABLED & USER_ENABLED via /api/users/{id}/disable et /api/users/{id}/enable
        $client->request('POST', '/api/users/' . $createdUserId . '/disable');
        self::assertResponseIsSuccessful();

        $disabledLogs = $auditRepo->findByFilters([
            'action' => AuditAction::UserDisabled->value,
            'entity_type' => 'User',
            'entity_id' => $createdUserId,
        ]);
        self::assertNotEmpty($disabledLogs, 'USER_DISABLED doit être journalisé.');

        $client->request('POST', '/api/users/' . $createdUserId . '/enable');
        self::assertResponseIsSuccessful();

        $enabledLogs = $auditRepo->findByFilters([
            'action' => AuditAction::UserEnabled->value,
            'entity_type' => 'User',
            'entity_id' => $createdUserId,
        ]);
        self::assertNotEmpty($enabledLogs, 'USER_ENABLED doit être journalisé.');

        // 4. USER_PERMISSION_CHANGED via modification d'un rôle dans /administration/roles/{id}/edit
        $role = $em->getRepository(AccessRole::class)->findOneBy(['code' => 'AGENT_COURRIER']);
        self::assertNotNull($role);

        $crawler = $client->request('GET', '/administration/roles/' . $role->getId() . '/edit');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $permIds = [];
        foreach ($role->getPermissions() as $perm) {
            $permIds[] = $perm->getId();
        }

        $client->request('POST', '/administration/roles/' . $role->getId() . '/edit', [
            '_token' => $token,
            'label' => $role->getLabel(),
            'description' => $role->getDescription() ?? 'Rôle courrier',
            'permissions' => $permIds,
        ]);
        self::assertResponseRedirects('/administration/roles');

        $permLogs = $auditRepo->findByFilters([
            'action' => AuditAction::UserPermissionChanged->value,
            'entity_type' => 'AccessRole',
            'entity_id' => (string) $role->getId(),
        ]);
        self::assertNotEmpty($permLogs, 'USER_PERMISSION_CHANGED doit être journalisé.');
    }

    /**
     * 18.2 & 18.4 — Tests Dossiers, Workflow, Documents et Intégrité :
     * Vérifie que chaque opération métier produit à la fois une entrée dans CaseEvent (timeline métier)
     * et une entrée distincte dans AuditLog (audit technique de sécurité), y compris en cas d'échec/refus.
     */
    public function testDossierWorkflowDocumentAndDualIntegrity(): void
    {
        $client = static::createClient();
        $em = $this->getEntityManager();
        $auditRepo = $this->getAuditRepository();
        $workflowService = self::getContainer()->get(WorkflowService::class);
        $incomingMailService = self::getContainer()->get(IncomingMailService::class);

        $agentCabinet = $this->getOrCreateUser('agent_cab_m10@mci.test', 'AGENT_CABINET', 'CABINET');
        $ministre = $this->getOrCreateUser('ministre_m10@mci.test', 'MINISTRE', 'MINISTERE');
        $sg = $this->getOrCreateUser('sg_m10@mci.test', 'SECRETAIRE_GENERAL', 'SECRETARIAT_GENERAL');
        $dgCommerce = $this->getOrCreateUser('dg_com_m10@mci.test', 'DIRECTEUR_GENERAL', 'DG_COMMERCE');
        $respDir = $this->getOrCreateUser('resp_dir_m10@mci.test', 'RESPONSABLE_DIRECTION', 'DIRECTION_COMMERCE_INTERIEUR');
        $agentTraitement = $this->getOrCreateUser('agent_trait_m10@mci.test', 'AGENT_TRAITEMENT', 'SERVICE_REGLEMENTATION');

        $docType = $em->getRepository(ReferenceItem::class)->findOneBy(['category' => ReferenceCategory::DocumentType]);
        $mailType = $em->getRepository(ReferenceItem::class)->findOneBy(['category' => ReferenceCategory::MailType]);
        $priority = $em->getRepository(ReferenceItem::class)->findOneBy(['category' => ReferenceCategory::Priority, 'code' => 'NORMALE']);
        $cabinetUnit = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'CABINET']);
        $minUnit = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'MINISTERE']);
        $sgUnit = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'SECRETARIAT_GENERAL']);
        $dgUnit = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DG_COMMERCE']);
        $dirUnit = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'DIRECTION_COMMERCE_INTERIEUR']);
        $srvUnit = $em->getRepository(OrganizationUnit::class)->findOneBy(['code' => 'SERVICE_REGLEMENTATION']);

        // 1. Création courrier/dossier + pièce initiale -> DOSSIER_CREATED + DOCUMENT_UPLOADED
        $tmpFile = tempnam(sys_get_temp_dir(), 'mci_audit_') . '.pdf';
        file_put_contents($tmpFile, '%PDF-1.4 Document test audit sécurité');
        $uploadedFile = new UploadedFile($tmpFile, 'courrier_audit.pdf', 'application/pdf', null, true);

        $res = $incomingMailService->registerIncomingMail([
            'subject' => 'Dossier test Module 10 Audit & Journalisation',
            'entry_channel' => EntryChannel::Cabinet,
            'received_at' => new \DateTimeImmutable('2026-10-01 09:00:00'),
            'sender_type' => SenderType::Organisation,
            'sender_name' => 'Consortium Industriel MCI',
            'document_type' => $docType,
            'mail_type' => $mailType,
            'priority' => $priority,
            'initial_structure' => $cabinetUnit,
            'observations' => 'Observations test audit',
        ], $agentCabinet, $uploadedFile);

        /** @var DossierCase $case */
        $case = $res['case'];
        $caseId = (string) $case->getId();

        // Vérifier DOSSIER_CREATED dans AuditLog
        $dossierCreatedLogs = $auditRepo->findByFilters([
            'action' => AuditAction::DossierCreated->value,
            'entity_type' => 'DossierCase',
            'entity_id' => $caseId,
        ]);
        self::assertNotEmpty($dossierCreatedLogs, 'DOSSIER_CREATED doit être journalisé.');
        self::assertSame('SUCCESS', $dossierCreatedLogs[0]->getResult());

        // Vérifier DOCUMENT_UPLOADED dans AuditLog
        $docUploadedLogs = $auditRepo->findByFilters([
            'action' => AuditAction::DocumentUploaded->value,
            'entity_type' => 'Document',
        ]);
        self::assertNotEmpty($docUploadedLogs, 'DOCUMENT_UPLOADED doit être journalisé.');

        // 2. Transitions Workflow :
        // Submit (Cabinet -> Ministre) => WORKFLOW_SUBMITTED + DOSSIER_FORWARDED + DOSSIER_STATUS_CHANGED
        $workflowService->submitToMinister($case, $agentCabinet, 'Soumission au Ministre', $minUnit);
        self::assertNotEmpty($auditRepo->findByFilters([
            'action' => AuditAction::WorkflowSubmitted->value,
            'entity_type' => 'DossierCase',
            'entity_id' => $caseId,
        ]), 'WORKFLOW_SUBMITTED doit être journalisé.');
        self::assertNotEmpty($auditRepo->findByFilters([
            'action' => AuditAction::DossierForwarded->value,
            'entity_type' => 'DossierCase',
            'entity_id' => $caseId,
        ]), 'DOSSIER_FORWARDED doit être journalisé.');
        self::assertNotEmpty($auditRepo->findByFilters([
            'action' => AuditAction::DossierStatusChanged->value,
            'entity_type' => 'DossierCase',
            'entity_id' => $caseId,
        ]), 'DOSSIER_STATUS_CHANGED doit être journalisé.');

        // Annotate (Ministre -> SG) => WORKFLOW_ANNOTATED
        $workflowService->annotateByMinister($case, $ministre, 'Pour instruction diligente', $sgUnit);
        self::assertNotEmpty($auditRepo->findByFilters([
            'action' => AuditAction::WorkflowAnnotated->value,
            'entity_type' => 'DossierCase',
            'entity_id' => $caseId,
        ]), 'WORKFLOW_ANNOTATED doit être journalisé.');

        // Orient (SG -> DG Commerce) => WORKFLOW_ORIENTED
        $workflowService->orientBySecretaryGeneral($case, $sg, 'Orientation DG Commerce', $dgUnit);
        self::assertNotEmpty($auditRepo->findByFilters([
            'action' => AuditAction::WorkflowOriented->value,
            'entity_type' => 'DossierCase',
            'entity_id' => $caseId,
        ]), 'WORKFLOW_ORIENTED doit être journalisé.');

        // Assign (DG Commerce -> Direction Commerce Intérieur) => WORKFLOW_ASSIGNED + DOSSIER_ASSIGNED
        $workflowService->assignToDirection($case, $dgCommerce, 'Affectation DCI', $dirUnit, $respDir);
        self::assertNotEmpty($auditRepo->findByFilters([
            'action' => AuditAction::WorkflowAssigned->value,
            'entity_type' => 'DossierCase',
            'entity_id' => $caseId,
        ]), 'WORKFLOW_ASSIGNED doit être journalisé.');
        self::assertNotEmpty($auditRepo->findByFilters([
            'action' => AuditAction::DossierAssigned->value,
            'entity_type' => 'DossierCase',
            'entity_id' => $caseId,
        ]), 'DOSSIER_ASSIGNED doit être journalisé.');

        // Assign to service (DCI -> Service Réglementation) => WORKFLOW_FORWARDED
        $workflowService->assignToService($case, $respDir, 'Pour traitement par le service', $srvUnit, $agentTraitement);
        self::assertNotEmpty($auditRepo->findByFilters([
            'action' => AuditAction::WorkflowForwarded->value,
            'entity_type' => 'DossierCase',
            'entity_id' => $caseId,
        ]), 'WORKFLOW_FORWARDED doit être journalisé.');

        // Return for correction => WORKFLOW_RETURNED
        $workflowService->returnForCorrection($case, $agentTraitement, 'Demande de précision à la direction', $dirUnit);
        self::assertNotEmpty($auditRepo->findByFilters([
            'action' => AuditAction::WorkflowReturned->value,
            'entity_type' => 'DossierCase',
            'entity_id' => $caseId,
        ]), 'WORKFLOW_RETURNED doit être journalisé.');

        // Re-assign to service & Complete processing => WORKFLOW_COMPLETED
        $workflowService->assignToService($case, $respDir, 'Précision apportée', $srvUnit, $agentTraitement);
        $workflowService->completeProcessing($case, $agentTraitement, 'Rapport finalisé', $dirUnit);
        self::assertNotEmpty($auditRepo->findByFilters([
            'action' => AuditAction::WorkflowCompleted->value,
            'entity_type' => 'DossierCase',
            'entity_id' => $caseId,
        ]), 'WORKFLOW_COMPLETED doit être journalisé.');

        // Validate (DCI -> Clôture) => WORKFLOW_VALIDATED + DOSSIER_ARCHIVED
        $workflowService->validateTreatment($case, $respDir, 'Validation définitive et archivage');
        self::assertNotEmpty($auditRepo->findByFilters([
            'action' => AuditAction::WorkflowValidated->value,
            'entity_type' => 'DossierCase',
            'entity_id' => $caseId,
        ]), 'WORKFLOW_VALIDATED doit être journalisé.');
        self::assertNotEmpty($auditRepo->findByFilters([
            'action' => AuditAction::DossierArchived->value,
            'entity_type' => 'DossierCase',
            'entity_id' => $caseId,
        ]), 'DOSSIER_ARCHIVED doit être journalisé.');

        // 3. Test d'intégrité (Section 18.4) : Séparation et coexistence CaseEvent (Timeline métier) vs AuditLog (Audit technique)
        $timelineEvents = $em->getRepository(CaseEvent::class)->findBy(['dossierCase' => $case]);
        $auditEvents = $auditRepo->findByFilters(['entity_type' => 'DossierCase', 'entity_id' => $caseId], 50, 0);
        self::assertGreaterThanOrEqual(8, count($timelineEvents), 'La timeline métier CaseEvent doit conserver les événements lisibles.');
        self::assertGreaterThanOrEqual(8, count($auditEvents), 'Le journal technique AuditLog doit conserver les traces techniques.');

        // 4. Test transition Workflow échouée/refusée via WorkflowController -> Génère FAILURE ou DENIED sans faux SUCCESS
        $client->loginUser($agentCabinet);
        $crawler = $client->request('GET', '/dossiers/' . $case->getId());
        // Tentative de transition non valide sur un dossier déjà clôturé
        $csrfTransition = $client->getContainer()->get('security.csrf.token_manager')->getToken('workflow_transition_' . $case->getId())->getValue();
        $client->request('POST', '/workflow/cases/' . $case->getId() . '/transition', [
            '_token' => $csrfTransition,
            'action' => 'submit',
            'comment' => 'Tentative invalide sur dossier clôturé',
        ]);
        self::assertResponseRedirects('/dossiers/' . $case->getId());

        $failedWorkflowLogs = $auditRepo->findByFilters([
            'entity_type' => 'DossierCase',
            'entity_id' => $caseId,
            'result' => AuditResult::Denied->value,
        ]);
        $failedTechLogs = $auditRepo->findByFilters([
            'entity_type' => 'DossierCase',
            'entity_id' => $caseId,
            'result' => AuditResult::Failure->value,
        ]);
        self::assertTrue(
            count($failedWorkflowLogs) > 0 || count($failedTechLogs) > 0,
            'Une transition workflow refusée ou en échec doit être journalisée avec résultat DENIED ou FAILURE.'
        );

        // 5. Opérations documentaires : DOCUMENT_DOWNLOADED (SUCCESS + DENIED) & DOCUMENT_VERSION_CREATED & DOCUMENT_ARCHIVED
        /** @var Document|null $doc */
        $doc = $em->getRepository(Document::class)->findOneBy(['dossierCase' => $case]);
        self::assertNotNull($doc);
        $docId = (string) $doc->getId();

        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'admin@mci.local']);
        self::assertNotNull($admin);
        $client->loginUser($admin);

        // Téléchargement autorisé -> DOCUMENT_DOWNLOADED (SUCCESS)
        $client->request('GET', '/dossiers/documents/' . $doc->getId() . '/download');
        self::assertResponseIsSuccessful();

        $downloadSuccessLogs = $auditRepo->findByFilters([
            'action' => AuditAction::DocumentDownloaded->value,
            'entity_type' => 'Document',
            'entity_id' => $docId,
            'result' => AuditResult::Success->value,
        ]);
        self::assertNotEmpty($downloadSuccessLogs, 'DOCUMENT_DOWNLOADED (SUCCESS) doit être journalisé.');

        // Téléchargement refusé pour un utilisateur hors périmètre -> DOCUMENT_DOWNLOADED (DENIED)
        $outsider = $this->getOrCreateUser('outsider_doc_m10@mci.test', 'AGENT_COURRIER', 'BUREAU_COURRIER');
        $client->loginUser($outsider);
        $client->request('GET', '/dossiers/documents/' . $doc->getId() . '/download');
        self::assertResponseStatusCodeSame(403);

        $downloadDeniedLogs = $auditRepo->findByFilters([
            'action' => AuditAction::DocumentDownloaded->value,
            'entity_type' => 'Document',
            'entity_id' => $docId,
            'result' => AuditResult::Denied->value,
        ]);
        self::assertNotEmpty($downloadDeniedLogs, 'DOCUMENT_DOWNLOADED (DENIED) doit être journalisé lors d\'un accès 403.');

        // Ajout d'une nouvelle version du document (même nom) -> DOCUMENT_VERSION_CREATED
        $client->loginUser($admin);
        $tmpFileV2 = tempnam(sys_get_temp_dir(), 'mci_audit_v2_') . '.pdf';
        file_put_contents($tmpFileV2, '%PDF-1.4 Document test audit version 2');
        $uploadedFileV2 = new UploadedFile($tmpFileV2, 'courrier_audit.pdf', 'application/pdf', null, true);

        $csrfUpload = $client->getContainer()->get('security.csrf.token_manager')->getToken('upload_doc_' . $case->getId())->getValue();
        $client->request(
            'POST',
            '/dossiers/' . $case->getId() . '/documents/upload',
            [
                '_token' => $csrfUpload,
                'document_type_id' => $docType ? $docType->getId() : '',
            ],
            [
                'document_file' => $uploadedFileV2,
            ]
        );
        self::assertResponseRedirects('/dossiers/' . $case->getId());

        $versionLogs = $auditRepo->findByFilters([
            'action' => AuditAction::DocumentVersionCreated->value,
            'entity_type' => 'Document',
        ]);
        self::assertNotEmpty($versionLogs, 'DOCUMENT_VERSION_CREATED doit être journalisé.');

        // Archivage documentaire -> DOCUMENT_ARCHIVED
        $csrfArchive = $client->getContainer()->get('security.csrf.token_manager')->getToken('archive_doc_' . $doc->getId())->getValue();
        $client->request('POST', '/dossiers/documents/' . $doc->getId() . '/archive', [
            '_token' => $csrfArchive,
        ]);
        self::assertResponseRedirects('/dossiers/' . $case->getId());

        $archivedDocLogs = $auditRepo->findByFilters([
            'action' => AuditAction::DocumentArchived->value,
            'entity_type' => 'Document',
            'entity_id' => $docId,
        ]);
        self::assertNotEmpty($archivedDocLogs, 'DOCUMENT_ARCHIVED doit être journalisé.');
    }

    /**
     * 18.3 — Tests Sécurité, Immuabilité et Interface d'Administration :
     * - Utilisateur sans AUDIT_VIEW reçoit 403
     * - Administrateur avec AUDIT_VIEW reçoit 200 (liste + détail + filtres)
     * - Aucune route ne permet de modifier ou supprimer un audit (PUT/PATCH/POST/DELETE -> 403)
     * - Doctrine PreUpdate / PreRemove lèvent une LogicException
     */
    public function testSecurityAccessControlAndImmutability(): void
    {
        $client = static::createClient();
        $em = $this->getEntityManager();
        $auditRepo = $this->getAuditRepository();

        // 1. Utilisateur non habilité (AGENT_COURRIER n'a pas AUDIT_VIEW) -> 403
        $standardUser = $this->getOrCreateUser('standard_no_audit@mci.test', 'AGENT_COURRIER', 'BUREAU_COURRIER');
        $client->loginUser($standardUser);

        $client->request('GET', '/administration/audit-logs');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/administration/audit');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/admin/audit');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/api/audit-logs');
        self::assertResponseStatusCodeSame(403);

        // Vérifier que le refus d'accès a lui-même généré un ACCESS_DENIED
        $accessDeniedLogs = $auditRepo->findByFilters([
            'action' => AuditAction::AccessDenied->value,
            'result' => AuditResult::Denied->value,
        ], 5, 0);
        self::assertNotEmpty($accessDeniedLogs, 'Une tentative d\'accès 403 doit générer un AuditLog ACCESS_DENIED.');

        // 2. Administrateur habilité (AUDIT_VIEW) -> 200 sur liste et détail, filtres combinés fonctionnels
        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'admin@mci.local']);
        self::assertNotNull($admin);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/administration/audit-logs', [
            'result' => 'DENIED',
            'action' => 'ACCESS_DENIED',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', "Journal d'audit et de sécurité");
        self::assertSelectorTextContains('body', 'Lecture seule');
        self::assertSelectorTextContains('body', 'ACCESS_DENIED');

        // Vérifier l'absence de tout bouton Supprimer ou Modifier un audit dans l'interface
        self::assertSame(0, $crawler->filter('button:contains("Supprimer"), a:contains("Supprimer"), button:contains("Modifier"), a:contains("Modifier")')->count());

        // Tester la vue détail d'un AuditLog
        $sampleLog = $accessDeniedLogs[0];
        $client->request('GET', '/administration/audit-logs/' . $sampleLog->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Audit #' . $sampleLog->getId());

        // Tester aussi l'endpoint API JSON en lecture seule
        $client->request('GET', '/api/audit-logs/' . $sampleLog->getId());
        self::assertResponseIsSuccessful();
        $jsonDetail = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($sampleLog->getId(), $jsonDetail['id']);
        self::assertSame('ACCESS_DENIED', $jsonDetail['action']);

        // 3. Protection backend contre toute tentative de modification ou suppression HTTP (PUT / PATCH / DELETE / POST)
        $logId = $sampleLog->getId();

        $client->request('PUT', '/audit/' . $logId);
        self::assertResponseStatusCodeSame(403);

        $client->request('DELETE', '/audit/' . $logId);
        self::assertResponseStatusCodeSame(403);

        $client->request('PUT', '/api/audit-logs/' . $logId);
        self::assertResponseStatusCodeSame(403);

        $client->request('DELETE', '/api/audit-logs/' . $logId);
        self::assertResponseStatusCodeSame(403);

        $client->request('DELETE', '/administration/audit-logs/' . $logId);
        self::assertResponseStatusCodeSame(403);

        $client->request('PATCH', '/administration/audit/' . $logId);
        self::assertResponseStatusCodeSame(403);

        // Vérifier que le log existe toujours intact en base
        $em->clear();
        $stillExists = $auditRepo->find($logId);
        self::assertNotNull($stillExists, 'Le journal d\'audit ne doit jamais pouvoir être supprimé.');

        // 4. Vérification de l'immuabilité Doctrine au niveau ORM (PreRemove lève LogicException)
        $this->expectException(\LogicException::class);
        $em->remove($stillExists);
        $em->flush();
    }
}
