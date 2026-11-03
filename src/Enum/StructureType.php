<?php

namespace App\Enum;

enum StructureType: string
{
    case Ministere = 'MINISTERE';
    case Cabinet = 'CABINET';
    case SecretariatGeneral = 'SECRETARIAT_GENERAL';
    case DirectionGenerale = 'DIRECTION_GENERALE';
    case Direction = 'DIRECTION';
    case Service = 'SERVICE';
    case StructureRattachee = 'STRUCTURE_RATTACHEE';
    case DelegationProvinciale = 'DELEGATION_PROVINCIALE';

    public function label(): string
    {
        return match ($this) {
            self::Ministere => 'Ministère',
            self::Cabinet => 'Cabinet',
            self::SecretariatGeneral => 'Secrétariat Général',
            self::DirectionGenerale => 'Direction Générale',
            self::Direction => 'Direction',
            self::Service => 'Service',
            self::StructureRattachee => 'Structure rattachée',
            self::DelegationProvinciale => 'Délégation provinciale',
        };
    }
}
