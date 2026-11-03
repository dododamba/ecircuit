<?php

namespace App\Enum;

enum SenderType: string
{
    case Personne       = 'PERSONNE';
    case Organisation   = 'ORGANISATION';
    case Administration = 'ADMINISTRATION';
    case Entreprise     = 'ENTREPRISE';
    case Partenaire     = 'PARTENAIRE';
    case Autre          = 'AUTRE';

    public function label(): string
    {
        return match ($this) {
            self::Personne       => 'Personne physique',
            self::Organisation   => 'Organisation',
            self::Administration => 'Administration',
            self::Entreprise     => 'Entreprise',
            self::Partenaire     => 'Partenaire institutionnel',
            self::Autre          => 'Autre',
        };
    }
}
