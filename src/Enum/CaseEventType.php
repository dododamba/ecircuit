<?php

namespace App\Enum;

enum CaseEventType: string
{
    // Canonical Actions Module 9
    case DossierCreated     = 'DOSSIER_CREATED';
    case Registered         = 'REGISTERED';
    case Submitted          = 'SUBMITTED';
    case Annotated          = 'ANNOTATED';
    case Oriented           = 'ORIENTED';
    case Forwarded          = 'FORWARDED';
    case Assigned           = 'ASSIGNED';
    case TakenInCharge      = 'TAKEN_IN_CHARGE';
    case DocumentAdded      = 'DOCUMENT_ADDED';
    case DocumentDownloaded = 'DOCUMENT_DOWNLOADED';
    case Processed          = 'PROCESSED';
    case Returned           = 'RETURNED';
    case Rejected           = 'REJECTED';
    case Validated          = 'VALIDATED';
    case Completed          = 'COMPLETED';
    case Released           = 'RELEASED';
    case Archived           = 'ARCHIVED';
    case WorkflowEscalated  = 'WORKFLOW_ESCALATED';
    case OutgoingMailCreated = 'OUTGOING_MAIL_CREATED';
    case OutgoingMailSent   = 'OUTGOING_MAIL_SENT';
    case DeadlineUpdated    = 'DEADLINE_UPDATED';

    // Legacy & Specialized Aliases
    case CaseCreated        = 'CASE_CREATED';
    case CaseRegistered     = 'CASE_REGISTERED';
    case CaseUpdated        = 'CASE_UPDATED';
    case CaseAssigned       = 'CASE_ASSIGNED';
    case DossierAffecte     = 'DOSSIER_AFFECTE';
    case CaseForwarded      = 'CASE_FORWARDED';
    case CaseReceived       = 'CASE_RECEIVED';
    case CaseInProgress     = 'CASE_IN_PROGRESS';
    case CasePending        = 'CASE_PENDING';
    case CaseValidated      = 'CASE_VALIDATED';
    case CaseClosed         = 'CASE_CLOSED';
    case CaseArchived       = 'CASE_ARCHIVED';
    case NoteAdded          = 'NOTE_ADDED';

    public function label(): string
    {
        return match ($this) {
            self::DossierCreated,
            self::CaseCreated        => 'Création du dossier',
            self::Registered,
            self::CaseRegistered     => 'Enregistrement initial',
            self::CaseUpdated        => 'Modification',
            self::Submitted          => 'Soumission du dossier',
            self::Annotated,
            self::NoteAdded          => 'Annotation / Note',
            self::Oriented           => 'Orientation du dossier',
            self::Forwarded,
            self::CaseForwarded      => 'Transmission du dossier',
            self::Assigned,
            self::CaseAssigned,
            self::DossierAffecte     => 'Affectation du dossier',
            self::TakenInCharge      => 'Prise en charge',
            self::Processed          => 'Traitement achevé',
            self::Returned           => 'Retour du dossier',
            self::Rejected           => 'Rejet formel',
            self::CaseReceived       => 'Réception',
            self::CaseInProgress     => 'Traitement en cours',
            self::CasePending        => 'En attente',
            self::Validated,
            self::CaseValidated      => 'Validation hiérarchique',
            self::Completed,
            self::CaseClosed         => 'Clôture du dossier',
            self::Released           => 'Sortie du circuit',
            self::Archived,
            self::CaseArchived       => 'Archivage officiel',
            self::DocumentAdded      => 'Document joint',
            self::DocumentDownloaded => 'Document téléchargé',
            self::WorkflowEscalated  => 'Escalade pour dépassement de délai',
            self::OutgoingMailCreated => 'Courrier sortant préparé',
            self::OutgoingMailSent   => 'Courrier sortant expédié',
            self::DeadlineUpdated    => 'Modification de l\'échéance',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DossierCreated,
            self::CaseCreated        => '📥',
            self::Registered,
            self::CaseRegistered     => '🔖',
            self::CaseUpdated        => '✏️',
            self::Submitted          => '📤',
            self::Annotated,
            self::NoteAdded          => '📝',
            self::Oriented           => '🧭',
            self::Forwarded,
            self::CaseForwarded      => '➡️',
            self::Assigned,
            self::CaseAssigned,
            self::DossierAffecte     => '👤',
            self::TakenInCharge      => '✋',
            self::Processed          => '💼',
            self::Returned           => '↩️',
            self::Rejected           => '❌',
            self::CaseReceived       => '📨',
            self::CaseInProgress     => '⚙️',
            self::CasePending        => '⏳',
            self::Validated,
            self::CaseValidated      => '✅',
            self::Completed,
            self::CaseClosed         => '🔒',
            self::Released           => '🚪',
            self::Archived,
            self::CaseArchived       => '📦',
            self::DocumentAdded      => '📄',
            self::DocumentDownloaded => '💾',
            self::WorkflowEscalated  => '⚠️',
            self::OutgoingMailCreated => '✉️',
            self::OutgoingMailSent   => '📮',
            self::DeadlineUpdated    => '⏰',
        };
    }

    public function keenIcon(): string
    {
        return match ($this) {
            self::DossierCreated,
            self::CaseCreated        => 'ki-folder-added',
            self::Registered,
            self::CaseRegistered     => 'ki-barcode',
            self::Submitted          => 'ki-send',
            self::Annotated,
            self::NoteAdded          => 'ki-message-text',
            self::Oriented           => 'ki-compass',
            self::Forwarded,
            self::CaseForwarded      => 'ki-arrow-right',
            self::Assigned,
            self::CaseAssigned,
            self::DossierAffecte     => 'ki-user-tick',
            self::TakenInCharge      => 'ki-handcart',
            self::Processed          => 'ki-setting-3',
            self::Returned           => 'ki-arrow-left',
            self::Rejected           => 'ki-cross-circle',
            self::Validated,
            self::CaseValidated      => 'ki-check-circle',
            self::Completed,
            self::CaseClosed         => 'ki-lock',
            self::Released           => 'ki-exit-up',
            self::Archived,
            self::CaseArchived       => 'ki-archive',
            self::DocumentAdded      => 'ki-file-up',
            self::DocumentDownloaded => 'ki-file-down',
            self::WorkflowEscalated  => 'ki-arrow-up',
            self::OutgoingMailCreated,
            self::OutgoingMailSent   => 'ki-Directbox-send',
            self::DeadlineUpdated    => 'ki-calendar-8',
            default                  => 'ki-time',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::DossierCreated,
            self::CaseCreated,
            self::Registered,
            self::CaseRegistered     => 'badge-outline badge-primary',
            self::Submitted,
            self::Oriented,
            self::Forwarded,
            self::CaseForwarded,
            self::OutgoingMailCreated => 'badge-primary',
            self::Assigned,
            self::CaseAssigned,
            self::DossierAffecte,
            self::TakenInCharge,
            self::Processed          => 'badge-info',
            self::Annotated,
            self::NoteAdded,
            self::DeadlineUpdated    => 'badge-warning',
            self::Returned,
            self::WorkflowEscalated  => 'badge-warning',
            self::Rejected           => 'badge-destructive',
            self::Validated,
            self::CaseValidated,
            self::Completed,
            self::CaseClosed,
            self::Released,
            self::OutgoingMailSent   => 'badge-success',
            self::Archived,
            self::CaseArchived       => 'badge-secondary',
            self::DocumentAdded,
            self::DocumentDownloaded => 'badge-outline badge-secondary',
            default                  => 'badge-secondary',
        };
    }

    public function category(): string
    {
        return match ($this) {
            self::Submitted,
            self::Oriented,
            self::Forwarded,
            self::CaseForwarded,
            self::Processed,
            self::Returned,
            self::Rejected,
            self::Released,
            self::CaseUpdated,
            self::CaseReceived,
            self::CaseInProgress,
            self::CasePending,
            self::WorkflowEscalated,
            self::DeadlineUpdated    => 'workflow',
            self::DocumentAdded,
            self::DocumentDownloaded,
            self::OutgoingMailCreated,
            self::OutgoingMailSent   => 'documents',
            self::Assigned,
            self::CaseAssigned,
            self::DossierAffecte,
            self::TakenInCharge      => 'affectations',
            self::Validated,
            self::CaseValidated,
            self::Completed,
            self::CaseClosed         => 'validations',
            self::Annotated,
            self::NoteAdded          => 'annotations',
            self::DossierCreated,
            self::CaseCreated,
            self::Registered,
            self::CaseRegistered,
            self::Archived,
            self::CaseArchived       => 'lifecycle',
        };
    }
}
