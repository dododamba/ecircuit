-- =============================================================================
-- MCI e-Circuit : Seed SQL idempotent (Permissions, Rôles, Structures, Users)
-- Compatible MySQL / MariaDB (phpMyAdmin / CLI)
-- =============================================================================

SET NAMES utf8mb4;

-- 1. PERMISSIONS GRANULAIRES
INSERT INTO `access_permissions` (`code`, `label`, `module`, `active`) VALUES
('AUTH_LOGIN', 'Connexion au système', 'Authentification', 1),
('AUTH_LOGOUT', 'Déconnexion', 'Authentification', 1),
('USER_VIEW', 'Consulter les utilisateurs', 'Utilisateurs', 1),
('USER_CREATE', 'Créer un compte utilisateur', 'Utilisateurs', 1),
('USER_UPDATE', 'Modifier un utilisateur', 'Utilisateurs', 1),
('USER_ACTIVATE', 'Activer un compte utilisateur', 'Utilisateurs', 1),
('USER_DEACTIVATE', 'Désactiver un compte utilisateur', 'Utilisateurs', 1),
('USER_RESET_PASSWORD', 'Réinitialiser le mot de passe', 'Utilisateurs', 1),
('ROLE_VIEW', 'Consulter les rôles', 'Accès', 1),
('ROLE_CREATE', 'Créer un rôle', 'Accès', 1),
('ROLE_UPDATE', 'Modifier un rôle', 'Accès', 1),
('ROLE_EDIT', 'Modifier un rôle', 'Accès', 1),
('PERMISSION_VIEW', 'Consulter les permissions', 'Accès', 1),
('PERMISSION_ASSIGN', 'Affecter des permissions aux rôles', 'Accès', 1),
('STRUCTURE_VIEW', 'Consulter les structures administratives', 'Organisation', 1),
('STRUCTURE_CREATE', 'Créer une structure', 'Organisation', 1),
('STRUCTURE_UPDATE', 'Modifier une structure', 'Organisation', 1),
('REFERENCE_VIEW', 'Consulter les référentiels métiers', 'Référentiels', 1),
('REFERENCE_CREATE', 'Créer un élément de référentiel', 'Référentiels', 1),
('REFERENCE_UPDATE', 'Modifier un élément de référentiel', 'Référentiels', 1),
('CIRCUIT_VIEW', 'Consulter les circuits administratifs', 'Circuits', 1),
('CIRCUIT_CREATE', 'Créer un circuit administratif', 'Circuits', 1),
('CIRCUIT_UPDATE', 'Modifier un circuit administratif', 'Circuits', 1),
('AUDIT_VIEW', 'Consulter les journaux d’audit', 'Audit', 1),
('COURRIER_VIEW', 'Consulter les courriers', 'Courrier', 1),
('COURRIER_CREATE', 'Enregistrer un courrier entrant', 'Courrier', 1),
('COURRIER_EDIT', 'Modifier un courrier', 'Courrier', 1),
('COURRIER_TRANSMIT', 'Transmettre un courrier', 'Courrier', 1),
('DOSSIER_VIEW', 'Consulter les courriers et dossiers', 'Dossiers', 1),
('DOSSIER_CREATE', 'Créer / enregistrer un dossier', 'Dossiers', 1),
('DOSSIER_EDIT', 'Modifier un dossier', 'Dossiers', 1),
('DOSSIER_ASSIGN', 'Affecter un dossier à une structure ou un agent', 'Dossiers', 1),
('DOSSIER_CLOSE', 'Clôturer un dossier', 'Dossiers', 1),
('DOSSIER_ARCHIVE', 'Archiver un dossier', 'Dossiers', 1),
('DOSSIER_ANNOTATE', 'Annoter un dossier', 'Dossiers', 1),
('DOSSIER_FORWARD', 'Transmettre / orienter un dossier', 'Dossiers', 1),
('DOSSIER_TREAT', 'Traiter un dossier', 'Dossiers', 1),
('DOSSIER_VALIDATE', 'Valider un dossier', 'Dossiers', 1),
('DOSSIER_SIGN', 'Signer un document / dossier', 'Dossiers', 1),
('DOCUMENT_VIEW', 'Consulter les documents et pièces jointes', 'Documents', 1),
('DOCUMENT_UPLOAD', 'Téléverser des pièces et documents', 'Documents', 1),
('DOCUMENT_DOWNLOAD', 'Télécharger les documents', 'Documents', 1),
('DOCUMENT_DELETE', 'Supprimer un document', 'Documents', 1)
ON DUPLICATE KEY UPDATE
    `label` = VALUES(`label`),
    `module` = VALUES(`module`),
    `active` = 1;

-- 2. RÔLES D'ACCÈS
INSERT INTO `access_roles` (`code`, `label`, `description`) VALUES
('ADMINISTRATEUR', 'Administrateur', 'Administration technique et fonctionnelle complète de la plateforme'),
('AGENT_CABINET', 'Agent Cabinet', 'Réception, enregistrement, numérisation et suivi des courriers au Cabinet'),
('MINISTRE', 'Ministre', 'Consultation, annotation, orientation, validation et signature ministérielle'),
('SECRETAIRE_GENERAL', 'Secrétaire Général', 'Réception, analyse, orientation vers directions générales et suivi'),
('DIRECTEUR_GENERAL', 'Directeur Général', 'Orientation vers les directions techniques et supervision'),
('RESPONSABLE_DIRECTION', 'Responsable de Direction', 'Affectation aux agents, traitement et validation technique'),
('AGENT_TRAITEMENT', 'Agent de traitement', 'Traitement opérationnel des dossiers et transmission hiérarchique'),
('SERVICE_COURRIER', 'Service Courrier', 'Réception, numérisation, enregistrement et transmission initiale des courriers')
ON DUPLICATE KEY UPDATE
    `label` = VALUES(`label`),
    `description` = VALUES(`description`);

-- 3. MATRICE RÔLES <-> PERMISSIONS
-- 3.1 ADMINISTRATEUR : Toutes les permissions
INSERT IGNORE INTO `access_role_permissions` (`access_role_id`, `access_permission_id`)
SELECT r.id, p.id
FROM `access_roles` r
CROSS JOIN `access_permissions` p
WHERE r.code = 'ADMINISTRATEUR';

-- 3.2 AGENT_TRAITEMENT
INSERT IGNORE INTO `access_role_permissions` (`access_role_id`, `access_permission_id`)
SELECT r.id, p.id
FROM `access_roles` r
JOIN `access_permissions` p ON p.code IN (
    'AUTH_LOGIN', 'AUTH_LOGOUT', 'STRUCTURE_VIEW', 'REFERENCE_VIEW',
    'COURRIER_VIEW', 'DOSSIER_VIEW', 'DOSSIER_TREAT', 'DOSSIER_FORWARD',
    'DOCUMENT_VIEW', 'DOCUMENT_UPLOAD', 'DOCUMENT_DOWNLOAD'
)
WHERE r.code = 'AGENT_TRAITEMENT';

-- 3.3 SERVICE_COURRIER
INSERT IGNORE INTO `access_role_permissions` (`access_role_id`, `access_permission_id`)
SELECT r.id, p.id
FROM `access_roles` r
JOIN `access_permissions` p ON p.code IN (
    'AUTH_LOGIN', 'AUTH_LOGOUT', 'STRUCTURE_VIEW', 'REFERENCE_VIEW',
    'COURRIER_VIEW', 'COURRIER_CREATE', 'COURRIER_EDIT', 'COURRIER_TRANSMIT',
    'DOSSIER_VIEW', 'DOSSIER_CREATE',
    'DOCUMENT_VIEW', 'DOCUMENT_UPLOAD', 'DOCUMENT_DOWNLOAD'
)
WHERE r.code = 'SERVICE_COURRIER';

-- 3.4 AGENT_CABINET
INSERT IGNORE INTO `access_role_permissions` (`access_role_id`, `access_permission_id`)
SELECT r.id, p.id
FROM `access_roles` r
JOIN `access_permissions` p ON p.code IN (
    'AUTH_LOGIN', 'AUTH_LOGOUT', 'STRUCTURE_VIEW', 'REFERENCE_VIEW', 'CIRCUIT_VIEW',
    'COURRIER_VIEW', 'COURRIER_CREATE', 'COURRIER_EDIT', 'COURRIER_TRANSMIT',
    'DOSSIER_VIEW', 'DOSSIER_CREATE', 'DOSSIER_FORWARD', 'DOSSIER_CLOSE',
    'DOCUMENT_VIEW', 'DOCUMENT_UPLOAD', 'DOCUMENT_DOWNLOAD'
)
WHERE r.code = 'AGENT_CABINET';

-- 3.5 MINISTRE
INSERT IGNORE INTO `access_role_permissions` (`access_role_id`, `access_permission_id`)
SELECT r.id, p.id
FROM `access_roles` r
JOIN `access_permissions` p ON p.code IN (
    'AUTH_LOGIN', 'AUTH_LOGOUT', 'STRUCTURE_VIEW', 'REFERENCE_VIEW', 'CIRCUIT_VIEW',
    'COURRIER_VIEW', 'DOSSIER_VIEW', 'DOSSIER_ANNOTATE', 'DOSSIER_FORWARD', 'DOSSIER_VALIDATE', 'DOSSIER_SIGN',
    'DOCUMENT_VIEW', 'DOCUMENT_DOWNLOAD'
)
WHERE r.code = 'MINISTRE';

-- 3.6 SECRETAIRE_GENERAL
INSERT IGNORE INTO `access_role_permissions` (`access_role_id`, `access_permission_id`)
SELECT r.id, p.id
FROM `access_roles` r
JOIN `access_permissions` p ON p.code IN (
    'AUTH_LOGIN', 'AUTH_LOGOUT', 'STRUCTURE_VIEW', 'REFERENCE_VIEW', 'CIRCUIT_VIEW',
    'COURRIER_VIEW', 'DOSSIER_VIEW', 'DOSSIER_ANNOTATE', 'DOSSIER_FORWARD', 'DOSSIER_ASSIGN', 'DOSSIER_VALIDATE', 'DOSSIER_CLOSE',
    'DOCUMENT_VIEW', 'DOCUMENT_DOWNLOAD'
)
WHERE r.code = 'SECRETAIRE_GENERAL';

-- 3.7 DIRECTEUR_GENERAL
INSERT IGNORE INTO `access_role_permissions` (`access_role_id`, `access_permission_id`)
SELECT r.id, p.id
FROM `access_roles` r
JOIN `access_permissions` p ON p.code IN (
    'AUTH_LOGIN', 'AUTH_LOGOUT', 'STRUCTURE_VIEW', 'REFERENCE_VIEW', 'CIRCUIT_VIEW',
    'COURRIER_VIEW', 'DOSSIER_VIEW', 'DOSSIER_ANNOTATE', 'DOSSIER_FORWARD', 'DOSSIER_ASSIGN', 'DOSSIER_VALIDATE',
    'DOCUMENT_VIEW', 'DOCUMENT_DOWNLOAD'
)
WHERE r.code = 'DIRECTEUR_GENERAL';

-- 3.8 RESPONSABLE_DIRECTION
INSERT IGNORE INTO `access_role_permissions` (`access_role_id`, `access_permission_id`)
SELECT r.id, p.id
FROM `access_roles` r
JOIN `access_permissions` p ON p.code IN (
    'AUTH_LOGIN', 'AUTH_LOGOUT', 'STRUCTURE_VIEW', 'REFERENCE_VIEW', 'CIRCUIT_VIEW',
    'COURRIER_VIEW', 'DOSSIER_VIEW', 'DOSSIER_ANNOTATE', 'DOSSIER_FORWARD', 'DOSSIER_ASSIGN', 'DOSSIER_TREAT', 'DOSSIER_VALIDATE',
    'DOCUMENT_VIEW', 'DOCUMENT_UPLOAD', 'DOCUMENT_DOWNLOAD'
)
WHERE r.code = 'RESPONSABLE_DIRECTION';

-- 4. STRUCTURES ORGANISATIONNELLES DE RATTACHEMENT
INSERT INTO `organization_units` (`code`, `name`, `type`, `active`, `display_order`, `created_at`, `updated_at`, `parent_id`)
VALUES ('MINISTERE', 'Ministère du Commerce et de l’Industrie', 'MINISTERE', 1, 1, NOW(), NOW(), NULL)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `active` = 1, `updated_at` = NOW();

INSERT INTO `organization_units` (`code`, `name`, `type`, `active`, `display_order`, `created_at`, `updated_at`, `parent_id`)
SELECT 'DG_COMMERCE', 'Direction Générale du Commerce', 'DIRECTION_GENERALE', 1, 4, NOW(), NOW(), id
FROM `organization_units` WHERE `code` = 'MINISTERE'
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `active` = 1, `updated_at` = NOW();

INSERT INTO `organization_units` (`code`, `name`, `type`, `active`, `display_order`, `created_at`, `updated_at`, `parent_id`)
SELECT 'DIRECTION_COMMERCE_INTERIEUR', 'Direction du Commerce Intérieur', 'DIRECTION', 1, 1, NOW(), NOW(), id
FROM `organization_units` WHERE `code` = 'DG_COMMERCE'
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `active` = 1, `updated_at` = NOW();

-- 5. UTILISATEURS
-- 5.1 admin (admin@mci.local / AdminDevSecret2026!)
INSERT INTO `users` (`email`, `first_name`, `last_name`, `phone`, `username`, `password`, `status`, `created_at`, `updated_at`, `organization_unit_id`)
SELECT
    'admin@mci.local',
    'Administrateur',
    'MCI',
    '+241 01 00 00 00',
    'admin',
    '$2y$13$3gUztoG8jrfpwzwFMp7hj.35IEXQfjGSv.WT9tcMVffvEHkXJhQtu',
    'ACTIVE',
    NOW(),
    NOW(),
    id
FROM `organization_units` WHERE `code` = 'MINISTERE'
ON DUPLICATE KEY UPDATE
    `username` = VALUES(`username`),
    `first_name` = VALUES(`first_name`),
    `last_name` = VALUES(`last_name`),
    `password` = VALUES(`password`),
    `status` = 'ACTIVE',
    `organization_unit_id` = VALUES(`organization_unit_id`),
    `updated_at` = NOW();

-- 5.2 user_test (user.test@mci.gov / Password123!)
INSERT INTO `users` (`email`, `first_name`, `last_name`, `phone`, `username`, `password`, `status`, `created_at`, `updated_at`, `organization_unit_id`)
SELECT
    'user.test@mci.gov',
    'Jean',
    'KOUASSI',
    '+225 07 01 02 03 04',
    'user_test',
    '$2y$13$DbfcticBV3S8xiC5FeeQqON0IOfRreRJml09x1x5JSkaVRUYKE1RO',
    'ACTIVE',
    NOW(),
    NOW(),
    id
FROM `organization_units` WHERE `code` = 'DIRECTION_COMMERCE_INTERIEUR'
ON DUPLICATE KEY UPDATE
    `username` = VALUES(`username`),
    `first_name` = VALUES(`first_name`),
    `last_name` = VALUES(`last_name`),
    `password` = VALUES(`password`),
    `status` = 'ACTIVE',
    `organization_unit_id` = VALUES(`organization_unit_id`),
    `updated_at` = NOW();

-- 5.3 admin_test (admin.test@mci.gov / Password123!)
INSERT INTO `users` (`email`, `first_name`, `last_name`, `phone`, `username`, `password`, `status`, `created_at`, `updated_at`, `organization_unit_id`)
SELECT
    'admin.test@mci.gov',
    'Administrateur',
    'TEST',
    '+225 07 00 00 00 01',
    'admin_test',
    '$2y$13$3JOIdihoHGaJJxMkXzRzouy5.kS702EjwLLQ.4p9AFGi855JlQTTm',
    'ACTIVE',
    NOW(),
    NOW(),
    id
FROM `organization_units` WHERE `code` = 'DIRECTION_COMMERCE_INTERIEUR'
ON DUPLICATE KEY UPDATE
    `username` = VALUES(`username`),
    `first_name` = VALUES(`first_name`),
    `last_name` = VALUES(`last_name`),
    `password` = VALUES(`password`),
    `status` = 'ACTIVE',
    `organization_unit_id` = VALUES(`organization_unit_id`),
    `updated_at` = NOW();

-- 6. AFFECTATION DES RÔLES AUX UTILISATEURS (user_access_roles)
INSERT IGNORE INTO `user_access_roles` (`user_id`, `access_role_id`)
SELECT u.id, r.id
FROM `users` u
JOIN `access_roles` r ON r.code = 'ADMINISTRATEUR'
WHERE u.email IN ('admin@mci.local', 'admin.test@mci.gov');

INSERT IGNORE INTO `user_access_roles` (`user_id`, `access_role_id`)
SELECT u.id, r.id
FROM `users` u
JOIN `access_roles` r ON r.code = 'AGENT_TRAITEMENT'
WHERE u.email = 'user.test@mci.gov';
