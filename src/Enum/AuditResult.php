<?php

declare(strict_types=1);

namespace App\Enum;

enum AuditResult: string
{
    case Success = 'SUCCESS';
    case Failure = 'FAILURE';
    case Denied = 'DENIED';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Succès',
            self::Failure => 'Échec',
            self::Denied => 'Refusé',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Success => 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-400',
            self::Failure => 'bg-red-50 text-red-700 border-red-200 dark:bg-red-950/30 dark:text-red-400',
            self::Denied => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/30 dark:text-amber-400',
        };
    }

    public function keenIcon(): string
    {
        return match ($this) {
            self::Success => 'ki-check-circle',
            self::Failure => 'ki-cross-circle',
            self::Denied => 'ki-shield-cross',
        };
    }
}
