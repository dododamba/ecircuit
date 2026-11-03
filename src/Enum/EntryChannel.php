<?php

namespace App\Enum;

enum EntryChannel: string
{
    case Cabinet            = 'CABINET';
    case SecretariatGeneral = 'SECRETARIAT_GENERAL';

    public function label(): string
    {
        return match ($this) {
            self::Cabinet            => 'Cabinet',
            self::SecretariatGeneral => 'Secrétariat Général',
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
