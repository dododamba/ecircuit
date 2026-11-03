<?php

declare(strict_types=1);

namespace App\Enum;

enum AuditAction: string
{
    // --- AUTHENTIFICATION ---
    case LoginSuccess = 'LOGIN_SUCCESS';
    case LoginFailure = 'LOGIN_FAILURE';
    case Logout = 'LOGOUT';
    case SessionExpired = 'SESSION_EXPIRED';
    case PasswordChanged = 'PASSWORD_CHANGED';
    case PasswordReset = 'PASSWORD_RESET';

    // --- UTILISATEURS ---
    case UserCreated = 'USER_CREATED';
    case UserUpdated = 'USER_UPDATED';
    case UserEnabled = 'USER_ENABLED';
    case UserDisabled = 'USER_DISABLED';
    case UserRoleChanged = 'USER_ROLE_CHANGED';
    case UserPermissionChanged = 'USER_PERMISSION_CHANGED';

    // --- DOSSIERS ---
    case DossierCreated = 'DOSSIER_CREATED';
    case DossierUpdated = 'DOSSIER_UPDATED';
    case DossierStatusChanged = 'DOSSIER_STATUS_CHANGED';
    case DossierAssigned = 'DOSSIER_ASSIGNED';
    case DossierForwarded = 'DOSSIER_FORWARDED';
    case DossierArchived = 'DOSSIER_ARCHIVED';

    // --- WORKFLOW ---
    case WorkflowSubmitted = 'WORKFLOW_SUBMITTED';
    case WorkflowAnnotated = 'WORKFLOW_ANNOTATED';
    case WorkflowOriented = 'WORKFLOW_ORIENTED';
    case WorkflowAssigned = 'WORKFLOW_ASSIGNED';
    case WorkflowForwarded = 'WORKFLOW_FORWARDED';
    case WorkflowReturned = 'WORKFLOW_RETURNED';
    case WorkflowRejected = 'WORKFLOW_REJECTED';
    case WorkflowValidated = 'WORKFLOW_VALIDATED';
    case WorkflowEscalated = 'WORKFLOW_ESCALATED';
    case WorkflowCompleted = 'WORKFLOW_COMPLETED';

    // --- DOCUMENTS ---
    case DocumentUploaded = 'DOCUMENT_UPLOADED';
    case DocumentDownloaded = 'DOCUMENT_DOWNLOADED';
    case DocumentUpdated = 'DOCUMENT_UPDATED';
    case DocumentVersionCreated = 'DOCUMENT_VERSION_CREATED';
    case DocumentArchived = 'DOCUMENT_ARCHIVED';
    case DocumentDeleted = 'DOCUMENT_DELETED';

    // --- COURRIER ---
    case CourrierRegistered = 'COURRIER_REGISTERED';
    case OutgoingMailCreated = 'OUTGOING_MAIL_CREATED';
    case OutgoingMailUpdated = 'OUTGOING_MAIL_UPDATED';
    case OutgoingMailSent = 'OUTGOING_MAIL_SENT';

    // --- ADMINISTRATION (RÔLES, STRUCTURES, RÉFÉRENTIELS, CIRCUITS) ---
    case RoleCreated = 'ROLE_CREATED';
    case RoleUpdated = 'ROLE_UPDATED';
    case StructureCreated = 'STRUCTURE_CREATED';
    case StructureUpdated = 'STRUCTURE_UPDATED';
    case StructureStatusChanged = 'STRUCTURE_STATUS_CHANGED';
    case ReferenceCreated = 'REFERENCE_CREATED';
    case ReferenceUpdated = 'REFERENCE_UPDATED';
    case ReferenceToggled = 'REFERENCE_TOGGLED';
    case CircuitCreated = 'CIRCUIT_CREATED';
    case CircuitUpdated = 'CIRCUIT_UPDATED';

    // --- SÉCURITÉ GLOBALE ---
    case AccessDenied = 'ACCESS_DENIED';

    public function category(): string
    {
        return match ($this) {
            self::LoginSuccess, self::LoginFailure, self::Logout,
            self::SessionExpired, self::PasswordChanged, self::PasswordReset => 'Authentification',

            self::UserCreated, self::UserUpdated, self::UserEnabled,
            self::UserDisabled, self::UserRoleChanged, self::UserPermissionChanged,
            self::RoleCreated, self::RoleUpdated => 'Utilisateurs & IAM',

            self::DossierCreated, self::DossierUpdated, self::DossierStatusChanged,
            self::DossierAssigned, self::DossierForwarded, self::DossierArchived,
            self::CourrierRegistered, self::OutgoingMailCreated,
            self::OutgoingMailUpdated, self::OutgoingMailSent => 'Dossiers & Courriers',

            self::WorkflowSubmitted, self::WorkflowAnnotated, self::WorkflowOriented,
            self::WorkflowAssigned, self::WorkflowForwarded, self::WorkflowReturned,
            self::WorkflowRejected, self::WorkflowValidated, self::WorkflowEscalated,
            self::WorkflowCompleted => 'Workflow Engine',

            self::DocumentUploaded, self::DocumentDownloaded, self::DocumentUpdated,
            self::DocumentVersionCreated, self::DocumentArchived, self::DocumentDeleted => 'Documents',

            self::StructureCreated, self::StructureUpdated, self::StructureStatusChanged,
            self::ReferenceCreated, self::ReferenceUpdated, self::ReferenceToggled,
            self::CircuitCreated, self::CircuitUpdated => 'Administration',

            self::AccessDenied => 'Sécurité',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::LoginSuccess => 'Connexion réussie',
            self::LoginFailure => 'Échec de connexion',
            self::Logout => 'Déconnexion',
            self::SessionExpired => 'Session expirée',
            self::PasswordChanged => 'Mot de passe modifié',
            self::PasswordReset => 'Réinitialisation de mot de passe',

            self::UserCreated => 'Utilisateur créé',
            self::UserUpdated => 'Utilisateur modifié',
            self::UserEnabled => 'Utilisateur activé',
            self::UserDisabled => 'Utilisateur désactivé',
            self::UserRoleChanged => 'Rôle utilisateur modifié',
            self::UserPermissionChanged => 'Permission utilisateur modifiée',

            self::DossierCreated => 'Dossier créé',
            self::DossierUpdated => 'Dossier mis à jour',
            self::DossierStatusChanged => 'Statut du dossier modifié',
            self::DossierAssigned => 'Dossier affecté',
            self::DossierForwarded => 'Dossier transmis',
            self::DossierArchived => 'Dossier archivé',
            self::CourrierRegistered => 'Courrier entrant enregistré',
            self::OutgoingMailCreated => 'Courrier sortant créé',
            self::OutgoingMailUpdated => 'Courrier sortant mis à jour',
            self::OutgoingMailSent => 'Courrier sortant expédié',

            self::WorkflowSubmitted => 'Soumission au circuit',
            self::WorkflowAnnotated => 'Annotation / Directive',
            self::WorkflowOriented => 'Orientation vers structure',
            self::WorkflowAssigned => 'Affectation workflow',
            self::WorkflowForwarded => 'Transmission hiérarchique',
            self::WorkflowReturned => 'Renvoi pour correction',
            self::WorkflowRejected => 'Rejet formel',
            self::WorkflowValidated => 'Validation hiérarchique',
            self::WorkflowEscalated => 'Escalade administrative',
            self::WorkflowCompleted => 'Clôture de circuit',

            self::DocumentUploaded => 'Téléversement de pièce',
            self::DocumentDownloaded => 'Téléchargement de document',
            self::DocumentUpdated => 'Mise à jour de document',
            self::DocumentVersionCreated => 'Nouvelle version de document',
            self::DocumentArchived => 'Document archivé',
            self::DocumentDeleted => 'Document supprimé',

            self::RoleCreated => 'Rôle créé',
            self::RoleUpdated => 'Rôle modifié',
            self::StructureCreated => 'Structure créée',
            self::StructureUpdated => 'Structure modifiée',
            self::StructureStatusChanged => 'Statut de structure modifié',
            self::ReferenceCreated => 'Référentiel créé',
            self::ReferenceUpdated => 'Référentiel modifié',
            self::ReferenceToggled => 'Statut de référentiel modifié',
            self::CircuitCreated => 'Circuit administratif créé',
            self::CircuitUpdated => 'Circuit administratif modifié',

            self::AccessDenied => 'Accès refusé',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::LoginSuccess, self::UserEnabled, self::WorkflowValidated,
            self::WorkflowCompleted => 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-400',

            self::LoginFailure, self::AccessDenied, self::WorkflowRejected,
            self::UserDisabled, self::DocumentDeleted => 'bg-red-50 text-red-700 border-red-200 dark:bg-red-950/30 dark:text-red-400',

            self::WorkflowReturned, self::PasswordChanged, self::PasswordReset,
            self::UserRoleChanged, self::UserPermissionChanged,
            self::RoleUpdated, self::StructureStatusChanged => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/30 dark:text-amber-400',

            self::DossierCreated, self::DossierForwarded, self::WorkflowSubmitted,
            self::WorkflowForwarded, self::WorkflowOriented, self::CourrierRegistered => 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/30 dark:text-blue-400',

            self::DocumentUploaded, self::DocumentDownloaded, self::DocumentVersionCreated,
            self::DocumentUpdated, self::DocumentArchived => 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-950/30 dark:text-purple-400',

            default => 'bg-secondary/40 text-foreground border-border',
        };
    }

    public function keenIcon(): string
    {
        return match ($this) {
            self::LoginSuccess => 'ki-entrance-left',
            self::LoginFailure, self::AccessDenied => 'ki-shield-cross',
            self::Logout => 'ki-exit-right',
            self::SessionExpired => 'ki-time-past',
            self::PasswordChanged, self::PasswordReset => 'ki-key',

            self::UserCreated, self::UserUpdated, self::UserEnabled,
            self::UserDisabled, self::UserRoleChanged, self::UserPermissionChanged,
            self::RoleCreated, self::RoleUpdated => 'ki-profile-user',

            self::DossierCreated, self::CourrierRegistered, self::OutgoingMailCreated => 'ki-folder-added',
            self::DossierUpdated, self::DossierStatusChanged, self::OutgoingMailUpdated => 'ki-document',
            self::DossierAssigned, self::WorkflowAssigned => 'ki-user-tick',
            self::DossierForwarded, self::WorkflowForwarded, self::WorkflowOriented, self::OutgoingMailSent => 'ki-send',
            self::DossierArchived, self::DocumentArchived => 'ki-archive',

            self::WorkflowSubmitted, self::CircuitCreated, self::CircuitUpdated => 'ki-route',
            self::WorkflowAnnotated => 'ki-notepad-edit',
            self::WorkflowReturned => 'ki-arrow-left',
            self::WorkflowRejected => 'ki-cross-circle',
            self::WorkflowValidated => 'ki-check-circle',
            self::WorkflowEscalated => 'ki-arrow-up',
            self::WorkflowCompleted => 'ki-flag',

            self::DocumentUploaded => 'ki-file-up',
            self::DocumentDownloaded => 'ki-file-down',
            self::DocumentUpdated, self::DocumentVersionCreated => 'ki-file-sheet',
            self::DocumentDeleted => 'ki-trash',

            self::StructureCreated, self::StructureUpdated, self::StructureStatusChanged => 'ki-bank',
            self::ReferenceCreated, self::ReferenceUpdated, self::ReferenceToggled => 'ki-setting-2',
        };
    }
}
