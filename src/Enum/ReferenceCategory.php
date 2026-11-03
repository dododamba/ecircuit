<?php

namespace App\Enum;

enum ReferenceCategory: string
{
    case DocumentType = 'DOCUMENT_TYPE';
    case MailType = 'MAIL_TYPE';
    case RequestType = 'REQUEST_TYPE';
    case DossierStatus = 'DOSSIER_STATUS';
    case Priority = 'PRIORITY';
    case TransmissionReason = 'TRANSMISSION_REASON';

    public function label(): string
    {
        return match ($this) {
            self::DocumentType => 'Types de documents',
            self::MailType => 'Types de courriers',
            self::RequestType => 'Types de demandes',
            self::DossierStatus => 'Statuts de dossiers',
            self::Priority => 'Priorités',
            self::TransmissionReason => 'Motifs de transmission',
        };
    }

    public function slug(): string
    {
        return match ($this) {
            self::DocumentType => 'document-types',
            self::MailType => 'correspondence-types',
            self::RequestType => 'request-types',
            self::DossierStatus => 'statuses',
            self::Priority => 'priorities',
            self::TransmissionReason => 'transmission-reasons',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        return match ($slug) {
            'document-types', 'document_types' => self::DocumentType,
            'correspondence-types', 'mail-types', 'mail_types' => self::MailType,
            'request-types', 'request_types' => self::RequestType,
            'statuses', 'dossier-statuses', 'dossier_statuses' => self::DossierStatus,
            'priorities' => self::Priority,
            'transmission-reasons', 'transmission_reasons' => self::TransmissionReason,
            default => self::tryFrom(strtoupper(str_replace('-', '_', $slug))),
        };
    }
}
