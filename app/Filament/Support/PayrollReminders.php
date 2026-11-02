<?php

namespace App\Filament\Support;

use App\Filament\Resources\LeaveEncashments\LeaveEncashmentResource;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Resources\ReimbursementClaims\ReimbursementClaimResource;
use App\Filament\Resources\SalaryHolds\SalaryHoldResource;
use App\Filament\Resources\TaxDeclarations\TaxDeclarationResource;
use App\Filament\Resources\TdsInvestments\TdsInvestmentResource;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity PO-4 (matrix 25 §25.6.8 #1–#5, #7, #8, HROne's "Reminders" card on the salary dashboard): what still waits
 * on payroll, each count from its register's own scoped query (the viewer's access scope, as the Payroll desk counts them)
 * and each opening that register on the matching preset view. An item is listed only when its register opens for the
 * viewer. Read-only; counts are across companies, as the registers list them.
 */
final class PayrollReminders
{
    /**
     * @return array<string, array{0: string, 1: class-string, 2: string, 3: Closure(Builder): Builder}> key => [label, resource, preset view, narrowing]
     */
    public static function items(): array
    {
        return [
            'declarations' => ['Pending proposed investment declarations', TaxDeclarationResource::class, 'submitted', fn (Builder $q) => $q->where('status', 'submitted')],
            'proofs' => ['Pending confirmed investment declarations (proofs to verify)', TdsInvestmentResource::class, 'pending', fn (Builder $q) => $q->where('proof_status', 'pending')],
            'loan_approvals' => ['Pending loan approvals', LoanResource::class, 'requests', fn (Builder $q) => $q->where('status', 'pending')],
            'loan_dispense' => ['Pending dispense loans (approved, to pay out)', LoanResource::class, 'to_disburse', fn (Builder $q) => $q->where('status', 'approved')],
            'claims' => ['Pending reimbursement claims', ReimbursementClaimResource::class, 'waiting', fn (Builder $q) => $q->where('status', 'pending')],
            'holds' => ['Release hold salary', SalaryHoldResource::class, 'held', fn (Builder $q) => $q->where('status', 'held')],
            'encashments' => ['Encash leaves (approved, not yet paid)', LeaveEncashmentResource::class, 'approved', fn (Builder $q) => $q->where('status', 'approved')],
        ];
    }

    /**
     * The reminders the signed-in person may open.
     *
     * @return list<array{key: string, label: string, count: int, url: string}>
     */
    public static function forViewer(): array
    {
        $out = [];
        foreach (self::items() as $key => [$label, $resource, $view, $narrow]) {
            if (! (bool) rescue(fn () => $resource::canAccess(), false, false)) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => $label,
                'count' => $narrow($resource::getEloquentQuery())->count(),
                'url' => $resource::getUrl('index', ['view' => $view]),
            ];
        }

        return $out;
    }
}
