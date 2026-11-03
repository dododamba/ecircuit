<?php

namespace App\Enum;

enum CorrespondenceStatus: string
{
    case Draft             = 'DRAFT';
    case PendingValidation = 'PENDING_VALIDATION';
    case Validated         = 'VALIDATED';
    case ReadyToSend       = 'READY_TO_SEND';
    case Sent              = 'SENT';
    case Released          = 'RELEASED';
    case Cancelled         = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft             => 'Brouillon',
            self::PendingValidation => 'En attente de validation',
            self::Validated         => 'Validé',
            self::ReadyToSend       => 'Prêt à expédier',
            self::Sent              => 'Expédié',
            self::Released          => 'Sorti',
            self::Cancelled         => 'Annulé',
        };
    }

    public function uiColor(): string
    {
        return match ($this) {
            self::Draft             => 'gray',
            self::PendingValidation => 'orange',
            self::Validated         => 'blue',
            self::ReadyToSend       => 'indigo',
            self::Sent,
            self::Released          => 'green',
            self::Cancelled         => 'red',
        };
    }
}
