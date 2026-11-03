<?php

namespace App\Enum;

enum WorkflowAction: string
{
    case Submit       = 'SUBMIT';
    case Annotate     = 'ANNOTATE';
    case Orient       = 'ORIENT';
    case Forward      = 'FORWARD';
    case Assign       = 'ASSIGN';
    case TakeInCharge = 'TAKE_IN_CHARGE';
    case Process      = 'PROCESS';
    case Return       = 'RETURN';
    case Validate     = 'VALIDATE';
    case Reject       = 'REJECT';
    case Close        = 'CLOSE';
    case Release      = 'RELEASE';
    case Escalate     = 'ESCALATE';

    public function label(): string
    {
        return match ($this) {
            self::Submit       => 'Soumettre',
            self::Annotate     => 'Annoter',
            self::Orient       => 'Orienter',
            self::Forward      => 'Transmettre',
            self::Assign       => 'Affecter',
            self::TakeInCharge => 'Prendre en charge',
            self::Process      => 'Traiter',
            self::Return       => 'Retourner',
            self::Validate     => 'Valider',
            self::Reject       => 'Rejeter',
            self::Close        => 'Clôturer',
            self::Release      => 'Sortir du circuit',
            self::Escalate     => 'Escalader (retard)',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Submit       => 'ki-send',
            self::Annotate     => 'ki-message-text',
            self::Orient       => 'ki-compass',
            self::Forward      => 'ki-arrow-right',
            self::Assign       => 'ki-user-tick',
            self::TakeInCharge => 'ki-handcart',
            self::Process      => 'ki-setting-3',
            self::Return       => 'ki-arrow-left',
            self::Validate     => 'ki-check-circle',
            self::Reject       => 'ki-cross-circle',
            self::Close        => 'ki-lock',
            self::Release      => 'ki-exit-up',
            self::Escalate     => 'ki-arrow-up',
        };
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::Submit, self::Forward, self::Orient => 'kt-btn-primary',
            self::Validate, self::Close, self::Release => 'kt-btn-success',
            self::Return, self::Escalate => 'kt-btn-warning',
            self::Reject => 'kt-btn-destructive',
            self::Annotate, self::Assign, self::TakeInCharge, self::Process => 'kt-btn-outline',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Submit, self::Forward, self::Orient => 'badge-primary',
            self::Validate, self::Close, self::Release => 'badge-success',
            self::Return, self::Escalate => 'badge-warning',
            self::Reject => 'badge-destructive',
            self::Annotate, self::Assign, self::TakeInCharge, self::Process => 'badge-secondary',
        };
    }
}
