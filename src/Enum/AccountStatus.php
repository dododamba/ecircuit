<?php

namespace App\Enum;

enum AccountStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Locked = 'LOCKED';
}
