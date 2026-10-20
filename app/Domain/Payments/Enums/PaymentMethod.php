<?php

namespace App\Domain\Payments\Enums;

/** SaaS.7: generic payment method families; the methods a market offers come from its provider, never assumed here. */
enum PaymentMethod: string
{
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case DirectDebit = 'direct_debit';
    case Wallet = 'wallet';
    case Local = 'local';
    case Other = 'other';
}
