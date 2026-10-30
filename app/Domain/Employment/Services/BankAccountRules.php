<?php

namespace App\Domain\Employment\Services;

use App\Domain\Employment\Models\BankAccountRule;

/**
 * HROne setup parity CA-5: checks an employee's account number against the rule for its bank — the rule whose IFSC
 * bank code matches the account's IFSC, else a rule named like the account's bank. No matching active rule: nothing
 * more is checked than before (up to 34 characters).
 */
final class BankAccountRules
{
    /** What is wrong with the account number (null when it fits its bank's rule, or the bank has none). */
    public function problem(?string $accountNumber, ?string $ifsc, ?string $bankName): ?string
    {
        if (blank($accountNumber)) {
            return null;
        }
        $rule = $this->ruleFor($ifsc, $bankName);

        return $rule?->problemWith((string) $accountNumber);
    }

    public function ruleFor(?string $ifsc, ?string $bankName): ?BankAccountRule
    {
        $rules = BankAccountRule::query()->where('status', 'active')->orderBy('id')->get();
        $prefix = filled($ifsc) ? strtoupper(substr(trim((string) $ifsc), 0, 4)) : null;
        $name = mb_strtolower(trim((string) $bankName));

        // An account with an IFSC is matched by its bank code, else by name among the rules that give no code; one
        // without an IFSC by name.
        return ($prefix !== null ? $rules->first(fn (BankAccountRule $r) => $r->ifsc_prefix === $prefix) : null)
            ?? ($name !== '' ? $rules->first(fn (BankAccountRule $r) => ($prefix === null || $r->ifsc_prefix === null) && filled($r->bank_name) && mb_strtolower(trim((string) $r->bank_name)) === $name) : null);
    }
}
