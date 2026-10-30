<?php

namespace App\Domain\Expense\Services;

use App\Domain\Expense\Models\ExpenseGroup;
use App\Domain\Expense\Models\ExpenseTaxType;
use App\Support\Tenancy\TenantContext;

/**
 * HROne setup parity EX-A: starter expense groups for every tenant and the tax types of the tenant's country
 * (config peopleos.expense.defaults; HROne seeds the four Indian GST heads). Names only: PeopleOS never ships a tax
 * rate. Seeding never overwrites what the tenant changed (firstOrCreate by code).
 */
final class ExpenseDefaults
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function seed(): void
    {
        foreach (config('peopleos.expense.defaults.groups', []) as $i => $row) {
            ExpenseGroup::query()->firstOrCreate(['code' => $row['code']], $row + ['sort_order' => ($i + 1) * 10, 'status' => 'active']);
        }
        $this->seedTaxTypes();
    }

    /** Adds the starter tax types of the tenant's country that are missing; returns how many were added. */
    public function seedTaxTypes(): int
    {
        $country = strtoupper((string) ($this->tenants->current()?->country_code ?? ''));
        $added = 0;
        foreach ((array) (config('peopleos.expense.defaults.tax_types', [])[$country] ?? []) as $i => $row) {
            $added += ExpenseTaxType::query()->firstOrCreate(['code' => $row['code']], $row + ['sort_order' => ($i + 1) * 10, 'status' => 'active'])->wasRecentlyCreated ? 1 : 0;
        }

        return $added;
    }

    /** Whether the tenant's country has starter tax types. */
    public function hasTaxDefaults(): bool
    {
        return ((array) (config('peopleos.expense.defaults.tax_types', [])[strtoupper((string) ($this->tenants->current()?->country_code ?? ''))] ?? [])) !== [];
    }
}
