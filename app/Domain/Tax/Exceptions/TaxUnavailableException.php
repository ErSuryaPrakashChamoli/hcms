<?php

namespace App\Domain\Tax\Exceptions;

use RuntimeException;

/**
 * SaaS.7: tax cannot be determined safely. Billing refuses to issue the invoice: no default tax is ever applied, and
 * missing configuration is never read as 0 %. The reason code names what is missing.
 */
final class TaxUnavailableException extends RuntimeException
{
    public const TAX_CONFIGURATION_MISSING = 'TAX_CONFIGURATION_MISSING';

    public const TAX_RULE_UNVERIFIED = 'TAX_RULE_UNVERIFIED';

    public const PLACE_OF_SUPPLY_UNRESOLVED = 'PLACE_OF_SUPPLY_UNRESOLVED';

    public const CUSTOMER_TAX_STATUS_UNRESOLVED = 'CUSTOMER_TAX_STATUS_UNRESOLVED';

    public const SUPPLIER_TAX_STATUS_UNRESOLVED = 'SUPPLIER_TAX_STATUS_UNRESOLVED';

    public const REGISTRATION_REQUIRED = 'REGISTRATION_REQUIRED';

    public const EXPORT_CONDITIONS_NOT_SATISFIED = 'EXPORT_CONDITIONS_NOT_SATISFIED';

    public const TAX_CLASSIFICATION_PENDING = 'TAX_CLASSIFICATION_PENDING';

    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct("[{$reasonCode}] {$message}");
    }
}
