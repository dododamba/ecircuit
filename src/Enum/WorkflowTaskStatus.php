<?php

namespace App\Enum;

enum WorkflowTaskStatus: string
{
    case Pending    = 'PENDING';
    case InProgress = 'IN_PROGRESS';
    case Completed  = 'COMPLETED';
    case Cancelled  = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Pending    => 'En attente',
            self::InProgress => 'En cours',
            self::Completed  => 'Terminée',
            self::Cancelled  => 'Annulée',
        };
    }
}
