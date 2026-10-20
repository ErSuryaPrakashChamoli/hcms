<?php

namespace App\Domain\Tax\Enums;

/** SaaS.7: whether the customer is registered for the indirect tax of its jurisdiction. */
enum TaxRegistration: string
{
    case Registered = 'registered';
    case Unregistered = 'unregistered';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registered',
            self::Unregistered => 'Not registered',
            self::NotApplicable => 'Not applicable',
        };
    }
}
