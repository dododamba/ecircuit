<?php

namespace App\Enum;

enum WorkflowInstanceStatus: string
{
    case Active    = 'ACTIVE';
    case Paused    = 'PAUSED';
    case Completed = 'COMPLETED';
    case Rejected  = 'REJECTED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Active    => 'En cours',
            self::Paused    => 'En pause',
            self::Completed => 'Terminé',
            self::Rejected  => 'Rejeté',
            self::Cancelled => 'Annulé',
        };
    }
}
