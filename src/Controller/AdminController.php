<?php

namespace App\Controller;

use App\Entity\AccessPermission;
use App\Entity\AccessRole;
use App\Entity\AdministrativeCircuit;
use App\Entity\CircuitStep;
use App\Entity\OrganizationUnit;
use App\Entity\ReferenceItem;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\ReferenceCategory;
use App\Enum\StructureType;
use App\Repository\AuditLogRepository;
use App\Repository\OrganizationUnitRepository;
use App\Repository\ReferenceItemRepository;
use App\Repository\UserRepository;
use App\Service\AccessScopeService;
use App\Service\AuditLogger;
use App\Service\PasswordResetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/administration')]
final class AdminController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AccessScopeService $access
    ) {
    }

    // ==========================================
    // UTILISATEURS
    // ==========================================

    #[Route('/users', name: 'admin_users', methods: ['GET'])]
    public function users(Request $request, UserRepository $userRepository): Response
    {
        $this->access->assertPermission($this->user(), 'USER_VIEW');

        $search = trim((string) $request->query->get('q', ''));
        $status = trim((string) $request->query->get('status', ''));
        $roleId = $request->query->getInt('role_id') ?: null;
        $structureId = $request->query->getInt('structure_id') ?: null;

        $users = $userRepository->findBySearchAndFilters($search, $status, $roleId, $structureId);
        $roles = $this->entityManager->getRepository(AccessRole::class)->findAll();
        $structures = $this->entityManager->getRepository(OrganizationUnit::class)->findBy([], ['name' => 'ASC']);

        return $this->render('admin/users.html.twig', [
            'users' => $users,
            'query' => $search,
            'current_status' => $status,
            'current_role_id' => $roleId,
            'current_structure_id' => $structureId,
            'roles' => $roles,
            'structures' => $structures,
        ]);
    }

    #[Route('/users/new', name: 'admin_user_new', methods: ['GET', 'POST'])]
    public function userNew(
        Request $request,
        UserPasswordHasherInterface $hasher,
        AuditLogger $audit
    ): Response {
        $this->access->assertPermission($this->user(), 'USER_CREATE');

        $roles = $this->entityManager->getRepository(AccessRole::class)->findAll();
        $structures = $this->entityManager->getRepository(OrganizationUnit::class)->findBy([], ['name' => 'ASC']);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_user_new', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $email = strtolower(trim((string) $request->request->get('email')));
            $firstName = trim((string) $request->request->get('firstName'));
            $lastName = trim((string) $request->request->get('lastName'));
            $password = (string) $request->request->get('password');
            $passwordConfirmation = (string) $request->request->get('password_confirmation');
            $phone = trim((string) $request->request->get('phone'));
            $username = trim((string) $request->request->get('username'));
            $structureId = $request->request->getInt('structure_id');
            $selectedRoleIds = (array) $request->request->all('roles');

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', 'Veuillez saisir une adresse email valide.');
                return $this->render('admin/user_form.html.twig', ['roles' => $roles, 'structures' => $structures, 'edit' => false]);
            }

            if ($this->entityManager->getRepository(User::class)->findOneBy(['email' => $email])) {
                $this->addFlash('error', 'Cette adresse email est déjà attribuée à un compte.');
                return $this->render('admin/user_form.html.twig', ['roles' => $roles, 'structures' => $structures, 'edit' => false]);
            }

            if (strlen($password) < 8 || $password !== $passwordConfirmation) {
                $this->addFlash('error', 'Le mot de passe doit comporter au moins 8 caractères et être confirmé identiquement.');
                return $this->render('admin/user_form.html.twig', ['roles' => $roles, 'structures' => $structures, 'edit' => false]);
            }

            $newUser = new User($email, $firstName, $lastName);
            $newUser->setPassword($hasher->hashPassword($newUser, $password));
            if ($phone !== '') {
                $newUser->setPhone($phone);
            }
            if ($username !== '') {
                $newUser->setUsername($username);
            }

            if ($structureId > 0) {
                $unit = $this->entityManager->getRepository(OrganizationUnit::class)->find($structureId);
                if ($unit) {
                    $newUser->setOrganizationUnit($unit);
                }
            }

            foreach ($selectedRoleIds as $rId) {
                $role = $this->entityManager->getRepository(AccessRole::class)->find((int) $rId);
                if ($role) {
                    $newUser->addAccessRole($role);
                }
            }

            $this->entityManager->persist($newUser);
            $this->entityManager->flush();

            $audit->log(
                event: \App\Enum\AuditAction::UserCreated,
                success: true,
                user: $this->user(),
                request: $request,
                context: [
                    'created_user_id' => $newUser->getId(),
                    'email' => $newUser->getEmail(),
                    'roles' => array_map(fn (AccessRole $r) => $r->getCode(), $newUser->getAccessRoles()->toArray()),
                ],
                entityType: 'User',
                entityId: (string) $newUser->getId()
            );
            $this->addFlash('success', "Le compte utilisateur {$newUser->getFullName()} a été créé avec succès.");

            return $this->redirectToRoute('admin_users');
        }

        return $this->render('admin/user_form.html.twig', [
            'roles' => $roles,
            'structures' => $structures,
            'edit' => false,
            'user' => null,
        ]);
    }

    #[Route('/users/{id}/edit', name: 'admin_user_edit', methods: ['GET', 'POST'])]
    public function userEdit(
        User $targetUser,
        Request $request,
        UserPasswordHasherInterface $hasher,
        AuditLogger $audit
    ): Response {
        $this->access->assertPermission($this->user(), 'USER_UPDATE');

        $roles = $this->entityManager->getRepository(AccessRole::class)->findAll();
        $structures = $this->entityManager->getRepository(OrganizationUnit::class)->findBy([], ['name' => 'ASC']);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_user_edit_'.$targetUser->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $previousRoles = array_values(array_map(fn (AccessRole $r) => $r->getCode(), $targetUser->getAccessRoles()->toArray()));
            sort($previousRoles);
            $previousActive = $targetUser->isActive();

            $firstName = trim((string) $request->request->get('firstName'));
            $lastName = trim((string) $request->request->get('lastName'));
            $phone = trim((string) $request->request->get('phone'));
            $username = trim((string) $request->request->get('username'));
            $structureId = $request->request->getInt('structure_id');
            $selectedRoleIds = (array) $request->request->all('roles');
            $newPassword = (string) $request->request->get('password');
            $statusStr = (string) $request->request->get('status');

            if ($firstName !== '') {
                $targetUser->setFirstName($firstName);
            }
            if ($lastName !== '') {
                $targetUser->setLastName($lastName);
            }
            $targetUser->setPhone($phone !== '' ? $phone : null);
            $targetUser->setUsername($username !== '' ? $username : null);

            if ($structureId > 0) {
                $unit = $this->entityManager->getRepository(OrganizationUnit::class)->find($structureId);
                $targetUser->setOrganizationUnit($unit);
            } else {
                $targetUser->setOrganizationUnit(null);
            }

            if ($statusStr !== '') {
                $statusEnum = AccountStatus::tryFrom($statusStr);
                if ($statusEnum) {
                    $targetUser->setStatus($statusEnum);
                }
            }

            $targetUser->clearAccessRoles();
            foreach ($selectedRoleIds as $rId) {
                $role = $this->entityManager->getRepository(AccessRole::class)->find((int) $rId);
                if ($role) {
                    $targetUser->addAccessRole($role);
                }
            }

            if ($newPassword !== '') {
                if (strlen($newPassword) < 8) {
                    $this->addFlash('error', 'Le nouveau mot de passe doit comporter au moins 8 caractères.');
                    return $this->render('admin/user_form.html.twig', ['roles' => $roles, 'structures' => $structures, 'edit' => true, 'user' => $targetUser]);
                }
                $targetUser->setPassword($hasher->hashPassword($targetUser, $newPassword));
            }

            $this->entityManager->flush();

            $newRoles = array_values(array_map(fn (AccessRole $r) => $r->getCode(), $targetUser->getAccessRoles()->toArray()));
            sort($newRoles);

            $audit->log(
                event: \App\Enum\AuditAction::UserUpdated,
                success: true,
                user: $this->user(),
                request: $request,
                context: [
                    'target_user_id' => $targetUser->getId(),
                    'email' => $targetUser->getEmail(),
                ],
                entityType: 'User',
                entityId: (string) $targetUser->getId()
            );

            if ($previousRoles !== $newRoles) {
                $audit->log(
                    event: \App\Enum\AuditAction::UserRoleChanged,
                    success: true,
                    user: $this->user(),
                    request: $request,
                    context: [
                        'target_user_id' => $targetUser->getId(),
                        'email' => $targetUser->getEmail(),
                        'previous_roles' => $previousRoles,
                        'new_roles' => $newRoles,
                    ],
                    entityType: 'User',
                    entityId: (string) $targetUser->getId()
                );
            }

            if ($previousActive !== $targetUser->isActive()) {
                $audit->log(
                    event: $targetUser->isActive() ? \App\Enum\AuditAction::UserEnabled : \App\Enum\AuditAction::UserDisabled,
                    success: true,
                    user: $this->user(),
                    request: $request,
                    context: [
                        'target_user_id' => $targetUser->getId(),
                        'email' => $targetUser->getEmail(),
                    ],
                    entityType: 'User',
                    entityId: (string) $targetUser->getId()
                );
            }

            if ($newPassword !== '') {
                $audit->log(
                    event: \App\Enum\AuditAction::PasswordChanged,
                    success: true,
                    user: $this->user(),
                    request: $request,
                    context: [
                        'target_user_id' => $targetUser->getId(),
                        'changed_by_admin' => true,
                    ],
                    entityType: 'User',
                    entityId: (string) $targetUser->getId()
                );
            }

            $this->addFlash('success', "Le compte de {$targetUser->getFullName()} a été mis à jour.");

            return $this->redirectToRoute('admin_users');
        }

        return $this->render('admin/user_form.html.twig', [
            'roles' => $roles,
            'structures' => $structures,
            'edit' => true,
            'user' => $targetUser,
        ]);
    }

    #[Route('/users/{id}/activate', name: 'admin_user_activate', methods: ['POST'])]
    public function activate(User $user, Request $request, AuditLogger $audit): Response
    {
        $this->access->assertPermission($this->user(), 'USER_ACTIVATE');
        if (!$this->isCsrfTokenValid('user_status_'.$user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $user->activate();
        $this->entityManager->flush();
        $audit->log(
            event: \App\Enum\AuditAction::UserEnabled,
            success: true,
            user: $this->user(),
            request: $request,
            context: [
                'target_user_id' => $user->getId(),
                'email' => $user->getEmail(),
            ],
            entityType: 'User',
            entityId: (string) $user->getId()
        );
        $this->addFlash('success', "Compte de {$user->getFullName()} réactivé.");

        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/{id}/deactivate', name: 'admin_user_deactivate', methods: ['POST'])]
    public function deactivate(User $user, Request $request, AuditLogger $audit): Response
    {
        $this->access->assertPermission($this->user(), 'USER_DEACTIVATE');
        if (!$this->isCsrfTokenValid('user_status_'.$user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $user->deactivate();
        $this->entityManager->flush();
        $audit->log(
            event: \App\Enum\AuditAction::UserDisabled,
            success: true,
            user: $this->user(),
            request: $request,
            context: [
                'target_user_id' => $user->getId(),
                'email' => $user->getEmail(),
            ],
            entityType: 'User',
            entityId: (string) $user->getId()
        );
        $this->addFlash('success', "Compte de {$user->getFullName()} désactivé.");

        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/{id}/reset-password', name: 'admin_user_reset_password', methods: ['POST'])]
    public function adminResetPassword(
        User $targetUser,
        Request $request,
        PasswordResetService $resetService,
        MailerInterface $mailer,
        AuditLogger $audit
    ): Response {
        $this->access->assertPermission($this->user(), 'USER_RESET_PASSWORD');
        if (!$this->isCsrfTokenValid('user_reset_'.$targetUser->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        [, $plainToken] = $resetService->issue($targetUser);
        $resetUrl = $this->generateUrl('app_password_reset', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_URL);

        $mail = (new Email())
            ->to($targetUser->getEmail())
            ->subject('Réinitialisation de votre accès MCI e-Circuit')
            ->text("Bonjour {$targetUser->getFullName()},\n\nUn administrateur a initié la réinitialisation de votre mot de passe.\nCliquez sur ce lien pour définir votre nouveau mot de passe :\n{$resetUrl}\n\nCe lien est valable 30 minutes.");

        $mailer->send($mail);
        $audit->log(
            event: \App\Enum\AuditAction::PasswordReset,
            success: true,
            user: $this->user(),
            request: $request,
            context: [
                'stage' => 'admin_initiated',
                'target_user_id' => $targetUser->getId(),
                'triggered_by_admin' => $this->user()->getId(),
            ],
            entityType: 'User',
            entityId: (string) $targetUser->getId()
        );

        $this->addFlash('success', "Lien de réinitialisation généré et envoyé à {$targetUser->getEmail()}.");

        return $this->redirectToRoute('admin_users');
    }

    // ==========================================
    // RÔLES & PERMISSIONS
    // ==========================================

    #[Route('/roles', name: 'admin_roles', methods: ['GET'])]
    public function roles(): Response
    {
        $this->access->assertPermission($this->user(), 'ROLE_VIEW');

        $roles = $this->entityManager->getRepository(AccessRole::class)->findAll();
        $permissions = $this->entityManager->getRepository(AccessPermission::class)->findBy([], ['module' => 'ASC', 'code' => 'ASC']);

        return $this->render('admin/roles.html.twig', [
            'roles' => $roles,
            'permissions' => $permissions,
        ]);
    }

    #[Route('/roles/new', name: 'admin_role_new', methods: ['GET', 'POST'])]
    public function roleNew(Request $request, AuditLogger $audit): Response
    {
        $this->access->assertPermission($this->user(), 'ROLE_CREATE');
        $allPermissions = $this->entityManager->getRepository(AccessPermission::class)->findBy([], ['module' => 'ASC', 'code' => 'ASC']);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_role_new', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $code = strtoupper(trim((string) $request->request->get('code')));
            $label = trim((string) $request->request->get('label'));
            $description = trim((string) $request->request->get('description'));
            $selectedPermissions = (array) $request->request->all('permissions');

            if ($code === '' || $label === '') {
                $this->addFlash('error', 'Le code et le libellé sont obligatoires.');
                return $this->render('admin/role_form.html.twig', ['permissions' => $allPermissions, 'edit' => false]);
            }

            if ($this->entityManager->getRepository(AccessRole::class)->findOneBy(['code' => $code])) {
                $this->addFlash('error', 'Un rôle avec cet identifiant technique existe déjà.');
                return $this->render('admin/role_form.html.twig', ['permissions' => $allPermissions, 'edit' => false]);
            }

            $role = new AccessRole($code, $label, $description !== '' ? $description : null);

            foreach ($selectedPermissions as $permCode) {
                $perm = $this->entityManager->getRepository(AccessPermission::class)->findOneBy(['code' => (string) $permCode]);
                if ($perm) {
                    $role->grant($perm);
                }
            }

            $this->entityManager->persist($role);
            $this->entityManager->flush();

            $audit->log('ROLE_CREATED', true, $this->user(), $request, ['role_code' => $role->getCode()]);
            $this->addFlash('success', "Le rôle {$role->getLabel()} a été créé.");

            return $this->redirectToRoute('admin_roles');
        }

        return $this->render('admin/role_form.html.twig', [
            'permissions' => $allPermissions,
            'edit' => false,
            'role' => null,
        ]);
    }

    #[Route('/roles/{id}/edit', name: 'admin_role_edit', methods: ['GET', 'POST'])]
    public function roleEdit(AccessRole $role, Request $request, AuditLogger $audit): Response
    {
        $this->access->assertPermission($this->user(), 'ROLE_UPDATE');
        $allPermissions = $this->entityManager->getRepository(AccessPermission::class)->findBy([], ['module' => 'ASC', 'code' => 'ASC']);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_role_edit_'.$role->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $label = trim((string) $request->request->get('label'));
            $description = trim((string) $request->request->get('description'));
            $selectedPermissions = (array) $request->request->all('permissions');

            if ($label !== '') {
                $role->setLabel($label);
            }
            $role->setDescription($description !== '' ? $description : null);

            // Synchronize permissions
            $this->access->assertPermission($this->user(), 'PERMISSION_ASSIGN');
            $previousPermissions = array_values(array_map(fn (AccessPermission $p) => $p->getCode(), $role->getPermissions()->toArray()));
            sort($previousPermissions);

            $role->clearPermissions();
            foreach ($selectedPermissions as $permCode) {
                $perm = $this->entityManager->getRepository(AccessPermission::class)->findOneBy(['code' => (string) $permCode]);
                if ($perm) {
                    $role->grant($perm);
                }
            }

            $this->entityManager->flush();

            $newPermissions = array_values(array_map(fn (AccessPermission $p) => $p->getCode(), $role->getPermissions()->toArray()));
            sort($newPermissions);

            $audit->log(
                event: \App\Enum\AuditAction::RoleUpdated,
                success: true,
                user: $this->user(),
                request: $request,
                context: ['role_code' => $role->getCode(), 'role_id' => $role->getId()],
                entityType: 'AccessRole',
                entityId: (string) $role->getId()
            );

            if ($previousPermissions !== $newPermissions) {
                $audit->log(
                    event: \App\Enum\AuditAction::UserPermissionChanged,
                    success: true,
                    user: $this->user(),
                    request: $request,
                    context: [
                        'role_code' => $role->getCode(),
                        'role_id' => $role->getId(),
                        'previous_permissions' => $previousPermissions,
                        'new_permissions' => $newPermissions,
                    ],
                    entityType: 'AccessRole',
                    entityId: (string) $role->getId()
                );
            }

            $this->addFlash('success', "Rôle {$role->getLabel()} mis à jour.");

            return $this->redirectToRoute('admin_roles');
        }

        return $this->render('admin/role_form.html.twig', [
            'permissions' => $allPermissions,
            'edit' => true,
            'role' => $role,
        ]);
    }

    // ==========================================
    // STRUCTURES (ORGANISATION ADMINISTRATIVE)
    // ==========================================

    #[Route('/structures', name: 'admin_structures', methods: ['GET'])]
    public function structures(OrganizationUnitRepository $repository): Response
    {
        $this->access->assertPermission($this->user(), 'STRUCTURE_VIEW');

        $rootUnits = $repository->findRootUnits();
        $allUnits = $repository->findAllOrdered();

        return $this->render('admin/structures.html.twig', [
            'structures' => $rootUnits,
            'total_count' => count($allUnits),
        ]);
    }

    #[Route('/structures/new', name: 'admin_structure_new', methods: ['GET', 'POST'])]
    public function structureNew(Request $request, AuditLogger $audit): Response
    {
        $this->access->assertPermission($this->user(), 'STRUCTURE_CREATE');
        $allStructures = $this->entityManager->getRepository(OrganizationUnit::class)->findBy([], ['name' => 'ASC']);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_structure_new', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $code = strtoupper(trim((string) $request->request->get('code')));
            $name = trim((string) $request->request->get('name'));
            $typeStr = (string) $request->request->get('type');
            $type = StructureType::tryFrom($typeStr);
            $parentId = $request->request->getInt('parent_id');
            $description = trim((string) $request->request->get('description'));
            $displayOrder = $request->request->getInt('display_order');

            if ($code === '' || $name === '' || !$type) {
                $this->addFlash('error', 'Le code, le nom et le type sont obligatoires.');
                return $this->render('admin/structure_form.html.twig', ['structures' => $allStructures, 'types' => StructureType::cases(), 'edit' => false]);
            }

            if ($this->entityManager->getRepository(OrganizationUnit::class)->findOneBy(['code' => $code])) {
                $this->addFlash('error', 'Ce code de structure existe déjà.');
                return $this->render('admin/structure_form.html.twig', ['structures' => $allStructures, 'types' => StructureType::cases(), 'edit' => false]);
            }

            $unit = new OrganizationUnit($code, $name, $type);
            $unit->setDisplayOrder($displayOrder);
            if ($description !== '') {
                $unit->setDescription($description);
            }

            if ($parentId > 0) {
                $parent = $this->entityManager->getRepository(OrganizationUnit::class)->find($parentId);
                if ($parent) {
                    $unit->setParent($parent);
                }
            }

            $this->entityManager->persist($unit);
            $this->entityManager->flush();

            $audit->log('STRUCTURE_CREATED', true, $this->user(), $request, ['structure_code' => $unit->getCode()]);
            $this->addFlash('success', "La structure {$unit->getName()} a été créée.");

            return $this->redirectToRoute('admin_structures');
        }

        return $this->render('admin/structure_form.html.twig', [
            'structures' => $allStructures,
            'types' => StructureType::cases(),
            'edit' => false,
            'unit' => null,
        ]);
    }

    #[Route('/structures/{id}/edit', name: 'admin_structure_edit', methods: ['GET', 'POST'])]
    public function structureEdit(OrganizationUnit $unit, Request $request, AuditLogger $audit): Response
    {
        $this->access->assertPermission($this->user(), 'STRUCTURE_UPDATE');
        $allStructures = $this->entityManager->getRepository(OrganizationUnit::class)->findBy([], ['name' => 'ASC']);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_structure_edit_'.$unit->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $name = trim((string) $request->request->get('name'));
            $typeStr = (string) $request->request->get('type');
            $type = StructureType::tryFrom($typeStr);
            $parentId = $request->request->getInt('parent_id');
            $description = trim((string) $request->request->get('description'));
            $displayOrder = $request->request->getInt('display_order');
            $active = $request->request->getBoolean('active', true);

            if ($name !== '') {
                $unit->setName($name);
            }
            if ($type) {
                $unit->setType($type);
            }
            $unit->setDescription($description !== '' ? $description : null);
            $unit->setDisplayOrder($displayOrder);
            $unit->setActive($active);

            if ($parentId > 0 && $parentId !== $unit->getId()) {
                $parent = $this->entityManager->getRepository(OrganizationUnit::class)->find($parentId);
                if ($parent) {
                    if ($parent->isWithin($unit)) {
                        $this->addFlash('error', 'Une structure ne peut pas avoir comme parent une de ses sous-structures.');
                        return $this->render('admin/structure_form.html.twig', [
                            'structures' => $allStructures,
                            'types' => StructureType::cases(),
                            'edit' => true,
                            'unit' => $unit,
                        ]);
                    }
                    $unit->setParent($parent);
                }
            } elseif ($parentId === 0) {
                $unit->setParent(null);
            }

            $this->entityManager->flush();
            $audit->log('STRUCTURE_UPDATED', true, $this->user(), $request, ['structure_code' => $unit->getCode()]);
            $this->addFlash('success', "Structure {$unit->getName()} mise à jour.");

            return $this->redirectToRoute('admin_structures');
        }

        return $this->render('admin/structure_form.html.twig', [
            'structures' => $allStructures,
            'types' => StructureType::cases(),
            'edit' => true,
            'unit' => $unit,
        ]);
    }

    #[Route('/structures/{id}/toggle', name: 'admin_structure_toggle', methods: ['POST'])]
    public function structureToggle(OrganizationUnit $unit, Request $request, AuditLogger $audit): Response
    {
        $this->access->assertPermission($this->user(), 'STRUCTURE_UPDATE');
        if (!$this->isCsrfTokenValid('structure_toggle_'.$unit->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $unit->setActive(!$unit->isActive());
        $this->entityManager->flush();

        $audit->log('STRUCTURE_STATUS_CHANGED', true, $this->user(), $request, ['structure_code' => $unit->getCode(), 'active' => $unit->isActive()]);
        $this->addFlash('success', sprintf('Structure "%s" %s.', $unit->getName(), $unit->isActive() ? 'activée' : 'désactivée'));

        return $this->redirectToRoute('admin_structures');
    }

    // ==========================================
    // RÉFÉRENTIELS MÉTIERS
    // ==========================================

    #[Route('/references', name: 'admin_references', methods: ['GET'])]
    public function references(Request $request): Response
    {
        $this->access->assertPermission($this->user(), 'REFERENCE_VIEW');

        $activeCategorySlug = $request->query->get('category', 'document-types');
        $categoryEnum = ReferenceCategory::fromSlug($activeCategorySlug) ?? ReferenceCategory::DocumentType;

        $items = $this->entityManager->getRepository(ReferenceItem::class)->findBy(
            ['category' => $categoryEnum],
            ['displayOrder' => 'ASC', 'label' => 'ASC']
        );

        $circuits = $this->entityManager->getRepository(AdministrativeCircuit::class)->findAll();

        return $this->render('admin/references.html.twig', [
            'current_category' => $categoryEnum,
            'categories' => ReferenceCategory::cases(),
            'items' => $items,
            'circuits' => $circuits,
        ]);
    }

    #[Route('/references/{category}/new', name: 'admin_reference_new', methods: ['GET', 'POST'])]
    public function referenceNew(string $category, Request $request, AuditLogger $audit): Response
    {
        $this->access->assertPermission($this->user(), 'REFERENCE_CREATE');
        $enum = ReferenceCategory::fromSlug($category);
        if (!$enum) {
            throw $this->createNotFoundException('Catégorie inconnue.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_ref_new', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $code = strtoupper(trim((string) $request->request->get('code')));
            $label = trim((string) $request->request->get('label'));
            $description = trim((string) $request->request->get('description'));
            $uiColor = trim((string) $request->request->get('uiColor'));
            $displayOrder = $request->request->getInt('display_order');

            if ($code === '' || $label === '') {
                $this->addFlash('error', 'Le code et le libellé sont obligatoires.');
                return $this->render('admin/reference_form.html.twig', ['category' => $enum, 'edit' => false]);
            }

            if ($this->entityManager->getRepository(ReferenceItem::class)->findOneBy(['category' => $enum, 'code' => $code])) {
                $this->addFlash('error', 'Ce code existe déjà dans cette catégorie.');
                return $this->render('admin/reference_form.html.twig', ['category' => $enum, 'edit' => false]);
            }

            $item = new ReferenceItem($enum, $code, $label, $description !== '' ? $description : null, $displayOrder);
            if ($uiColor !== '') {
                $item->setUiColor($uiColor);
            }

            $this->entityManager->persist($item);
            $this->entityManager->flush();

            $audit->log('REFERENCE_CREATED', true, $this->user(), $request, ['category' => $enum->value, 'code' => $code]);
            $this->addFlash('success', "Élément {$item->getLabel()} ajouté au référentiel.");

            return $this->redirectToRoute('admin_references', ['category' => $enum->slug()]);
        }

        return $this->render('admin/reference_form.html.twig', [
            'category' => $enum,
            'edit' => false,
            'item' => null,
        ]);
    }

    #[Route('/references/{category}/{id}/edit', name: 'admin_reference_edit', methods: ['GET', 'POST'])]
    public function referenceEdit(string $category, int $id, Request $request, AuditLogger $audit): Response
    {
        $this->access->assertPermission($this->user(), 'REFERENCE_UPDATE');
        $enum = ReferenceCategory::fromSlug($category);
        if (!$enum) {
            throw $this->createNotFoundException('Catégorie inconnue.');
        }

        $item = $this->entityManager->getRepository(ReferenceItem::class)->find($id);
        if (!$item) {
            throw $this->createNotFoundException('Élément non trouvé.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_ref_edit_'.$item->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $label = trim((string) $request->request->get('label'));
            $description = trim((string) $request->request->get('description'));
            $uiColor = trim((string) $request->request->get('uiColor'));
            $displayOrder = $request->request->getInt('display_order');
            $active = $request->request->getBoolean('active', true);

            if ($label !== '') {
                $item->setLabel($label);
            }
            $item->setDescription($description !== '' ? $description : null);
            $item->setUiColor($uiColor !== '' ? $uiColor : null);
            $item->setDisplayOrder($displayOrder);
            $item->setActive($active);

            $this->entityManager->flush();

            $audit->log('REFERENCE_UPDATED', true, $this->user(), $request, ['code' => $item->getCode()]);
            $this->addFlash('success', "Élément {$item->getLabel()} mis à jour.");

            return $this->redirectToRoute('admin_references', ['category' => $enum->slug()]);
        }

        return $this->render('admin/reference_form.html.twig', [
            'category' => $enum,
            'edit' => true,
            'item' => $item,
        ]);
    }

    #[Route('/references/{category}/{id}/toggle', name: 'admin_reference_toggle', methods: ['POST'])]
    public function referenceToggle(string $category, int $id, Request $request, AuditLogger $audit): Response
    {
        $this->access->assertPermission($this->user(), 'REFERENCE_UPDATE');
        $item = $this->entityManager->getRepository(ReferenceItem::class)->find($id);
        if (!$item) {
            throw $this->createNotFoundException('Élément introuvable.');
        }

        if (!$this->isCsrfTokenValid('ref_toggle_'.$item->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $item->setActive(!$item->isActive());
        $this->entityManager->flush();

        $audit->log('REFERENCE_TOGGLED', true, $this->user(), $request, ['code' => $item->getCode(), 'active' => $item->isActive()]);
        $this->addFlash('success', sprintf('Élément "%s" %s.', $item->getLabel(), $item->isActive() ? 'activé' : 'désactivé'));

        return $this->redirectToRoute('admin_references', ['category' => $item->getCategory()->slug()]);
    }

    // ==========================================
    // CIRCUITS ADMINISTRATIFS
    // ==========================================

    #[Route('/circuits', name: 'admin_circuits', methods: ['GET'])]
    public function circuits(): Response
    {
        $this->access->assertPermission($this->user(), 'CIRCUIT_VIEW');

        $circuits = $this->entityManager->getRepository(AdministrativeCircuit::class)->findAll();

        return $this->render('admin/circuits.html.twig', [
            'circuits' => $circuits,
        ]);
    }

    #[Route('/circuits/new', name: 'admin_circuit_new', methods: ['GET', 'POST'])]
    public function circuitNew(Request $request, AuditLogger $audit, \App\Service\CircuitWorkflowSyncService $circuitSyncService): Response
    {
        $this->access->assertPermission($this->user(), 'CIRCUIT_CREATE');
        $structures = $this->entityManager->getRepository(OrganizationUnit::class)->findBy([], ['name' => 'ASC']);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_circuit_new', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $code = strtoupper(trim((string) $request->request->get('code')));
            $label = trim((string) $request->request->get('label'));
            $description = trim((string) $request->request->get('description'));
            $stepUnits = (array) $request->request->all('step_units');
            $stepLabels = (array) $request->request->all('step_labels');
            $stepDurations = (array) $request->request->all('step_durations');

            if ($code === '' || $label === '') {
                $this->addFlash('error', 'Le code et le libellé du circuit sont obligatoires.');
                return $this->render('admin/circuit_form.html.twig', ['structures' => $structures, 'edit' => false]);
            }

            if ($this->entityManager->getRepository(AdministrativeCircuit::class)->findOneBy(['code' => $code])) {
                $this->addFlash('error', 'Un circuit avec ce code existe déjà.');
                return $this->render('admin/circuit_form.html.twig', ['structures' => $structures, 'edit' => false]);
            }

            $circuit = new AdministrativeCircuit($code, $label, $description !== '' ? $description : null);

            $pos = 1;
            foreach ($stepUnits as $idx => $uId) {
                if ((int) $uId > 0) {
                    $unit = $this->entityManager->getRepository(OrganizationUnit::class)->find((int) $uId);
                    if ($unit) {
                        $sLabel = !empty($stepLabels[$idx]) ? trim((string) $stepLabels[$idx]) : null;
                        $duration = !empty($stepDurations[$idx]) ? (int) $stepDurations[$idx] : 48;
                        new CircuitStep($circuit, $unit, $pos++, $sLabel, $duration);
                    }
                }
            }

            $this->entityManager->persist($circuit);
            $this->entityManager->flush();

            // Synchronisation dynamique vers le moteur de workflow (S3.11)
            $circuitSyncService->syncCircuitToWorkflow($circuit);

            $audit->log('CIRCUIT_CREATED', true, $this->user(), $request, ['code' => $circuit->getCode()]);
            $this->addFlash('success', "Le circuit administratif {$circuit->getLabel()} a été créé et synchronisé avec le moteur de workflow.");

            return $this->redirectToRoute('admin_circuits');
        }

        return $this->render('admin/circuit_form.html.twig', [
            'structures' => $structures,
            'edit' => false,
            'circuit' => null,
        ]);
    }

    #[Route('/circuits/{id}/edit', name: 'admin_circuit_edit', methods: ['GET', 'POST'])]
    public function circuitEdit(AdministrativeCircuit $circuit, Request $request, AuditLogger $audit, \App\Service\CircuitWorkflowSyncService $circuitSyncService): Response
    {
        $this->access->assertPermission($this->user(), 'CIRCUIT_UPDATE');
        $structures = $this->entityManager->getRepository(OrganizationUnit::class)->findBy([], ['name' => 'ASC']);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_circuit_edit_'.$circuit->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $label = trim((string) $request->request->get('label'));
            $description = trim((string) $request->request->get('description'));
            $stepUnits = (array) $request->request->all('step_units');
            $stepLabels = (array) $request->request->all('step_labels');
            $stepDurations = (array) $request->request->all('step_durations');

            if ($label !== '') {
                $circuit->setLabel($label);
            }
            $circuit->setDescription($description !== '' ? $description : null);

            // Rebuild steps
            $circuit->clearSteps();
            $pos = 1;
            foreach ($stepUnits as $idx => $uId) {
                if ((int) $uId > 0) {
                    $unit = $this->entityManager->getRepository(OrganizationUnit::class)->find((int) $uId);
                    if ($unit) {
                        $sLabel = !empty($stepLabels[$idx]) ? trim((string) $stepLabels[$idx]) : null;
                        $duration = !empty($stepDurations[$idx]) ? (int) $stepDurations[$idx] : 48;
                        new CircuitStep($circuit, $unit, $pos++, $sLabel, $duration);
                    }
                }
            }

            $this->entityManager->flush();

            // Synchronisation dynamique vers le moteur de workflow (S3.11)
            $circuitSyncService->syncCircuitToWorkflow($circuit);

            $audit->log('CIRCUIT_UPDATED', true, $this->user(), $request, ['code' => $circuit->getCode()]);
            $this->addFlash('success', "Circuit {$circuit->getLabel()} mis à jour et synchronisé avec le moteur de workflow.");

            return $this->redirectToRoute('admin_circuits');
        }

        return $this->render('admin/circuit_form.html.twig', [
            'structures' => $structures,
            'edit' => true,
            'circuit' => $circuit,
        ]);
    }

    // ==========================================
    // JOURNAUX D’AUDIT & SÉCURITÉ (MODULE 10)
    // ==========================================

    #[Route('/audit-logs', name: 'admin_audit_logs', methods: ['GET'])]
    #[Route('/audit', name: 'admin_audit_index', methods: ['GET'])]
    public function auditLogs(Request $request, AuditLogRepository $auditRepository): Response
    {
        $this->access->assertPermission($this->user(), 'AUDIT_VIEW');

        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(100, max(10, $request->query->getInt('limit', 30)));
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

        $logs = $auditRepository->findByFilters($filters, $limit, $offset, $sort);
        $totalCount = $auditRepository->countByFilters($filters);
        $totalPages = max(1, (int) ceil($totalCount / $limit));
        $stats = $auditRepository->getSummaryStats();
        $entityTypes = $auditRepository->findDistinctEntityTypes();

        $users = $this->entityManager->getRepository(User::class)->findBy([], ['lastName' => 'ASC', 'firstName' => 'ASC']);
        $structures = $this->entityManager->getRepository(OrganizationUnit::class)->findBy([], ['name' => 'ASC']);
        $roles = $this->entityManager->getRepository(AccessRole::class)->findAll();

        return $this->render('admin/audit_logs.html.twig', [
            'logs' => $logs,
            'filters' => $filters,
            'selected_event' => $actionFilter,
            'limit' => $limit,
            'sort' => $sort,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_count' => $totalCount,
            'stats' => $stats,
            'entity_types' => $entityTypes,
            'audit_actions' => \App\Enum\AuditAction::cases(),
            'audit_results' => \App\Enum\AuditResult::cases(),
            'users' => $users,
            'structures' => $structures,
            'roles' => $roles,
        ]);
    }

    #[Route('/audit-logs/{id}', name: 'admin_audit_log_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[Route('/audit/{id}', name: 'admin_audit_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function auditLogShow(\App\Entity\AuditLog $log): Response
    {
        $this->access->assertPermission($this->user(), 'AUDIT_VIEW');

        return $this->render('admin/audit_log_show.html.twig', [
            'log' => $log,
        ]);
    }

    #[Route('/audit-logs/{id}', name: 'admin_audit_log_forbidden_mutation', methods: ['POST', 'PUT', 'PATCH', 'DELETE'], requirements: ['id' => '\d+'])]
    #[Route('/audit/{id}', name: 'admin_audit_forbidden_mutation', methods: ['POST', 'PUT', 'PATCH', 'DELETE'], requirements: ['id' => '\d+'])]
    public function forbidAuditMutation(int $id, Request $request, AuditLogger $audit): never
    {
        $request->attributes->set('_audit_access_denied_logged', true);
        $audit->log(
            event: \App\Enum\AuditAction::AccessDenied,
            success: false,
            user: $this->getUser() instanceof User ? $this->getUser() : null,
            request: $request,
            context: [
                'reason' => 'audit_log_immutable_mutation_forbidden',
                'attempted_method' => $request->getMethod(),
                'target_audit_id' => $id,
            ],
            entityType: 'AuditLog',
            entityId: (string) $id,
            result: \App\Enum\AuditResult::Denied,
            statusCode: 403
        );

        throw $this->createAccessDeniedException('Les journaux d\'audit de sécurité sont strictement immuables (lecture seule).');
    }

    private function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Non authentifié.');
        }
        return $user;
    }
}
