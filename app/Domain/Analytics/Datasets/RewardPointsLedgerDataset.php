<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Recognition\Models\RewardPointEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne setup parity HP-9d: the reward points ledger (deposits, cycle credits, awards, badge points, redemptions, lapses,
 * expiry, adjustments), for HR with recognition.points. Employee rows follow the viewer's access scope; budget rows have
 * no employee.
 */
class RewardPointsLedgerDataset extends Dataset
{
    public function key(): string
    {
        return 'reward_points_ledger';
    }

    public function label(): string
    {
        return 'Reward points ledger';
    }

    public function category(): string
    {
        return 'People';
    }

    public function permissions(): array
    {
        return ['recognition.points'];
    }

    public function fields(): array
    {
        return [
            'date' => ['label' => 'Date', 'type' => 'date', 'value' => fn (RewardPointEntry $e) => $e->created_at],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn (RewardPointEntry $e) => $e->created_at?->format('Y-m')],
            'wallet' => ['label' => 'Wallet', 'type' => 'string', 'value' => fn (RewardPointEntry $e) => RewardPointEntry::WALLETS[$e->wallet] ?? $e->wallet],
            'kind' => ['label' => 'Entry', 'type' => 'string', 'value' => fn (RewardPointEntry $e) => RewardPointEntry::KINDS[$e->kind] ?? $e->kind],
            'kind_code' => ['label' => 'Entry code', 'type' => 'string', 'value' => fn (RewardPointEntry $e) => $e->kind],
            'points' => ['label' => 'Points', 'type' => 'number', 'value' => fn (RewardPointEntry $e) => (int) $e->points],
            'amount' => ['label' => 'Amount redeemed', 'type' => 'number', 'value' => fn (RewardPointEntry $e) => $e->amount === null ? null : (float) $e->amount],
            'currency' => ['label' => 'Currency', 'type' => 'string', 'value' => fn (RewardPointEntry $e) => $e->currency],
            'cycle' => ['label' => 'Cycle', 'type' => 'string', 'value' => fn (RewardPointEntry $e) => $e->cycle_key],
            ...$this->employeeFields(fn (RewardPointEntry $e) => $e->employee),
            'colleague' => ['label' => 'From / to', 'type' => 'string', 'value' => fn (RewardPointEntry $e) => $e->counterpart?->person?->full_name],
            'note' => ['label' => 'Message', 'type' => 'string', 'value' => fn (RewardPointEntry $e) => $e->note],
            'paid_in_payroll' => ['label' => 'Paid in payroll', 'type' => 'boolean', 'value' => fn (RewardPointEntry $e) => $e->kind === 'redeem' ? $e->payroll_run_id !== null : null],
            'by' => ['label' => 'By', 'type' => 'string', 'value' => fn (RewardPointEntry $e) => $e->creator?->name],
        ];
    }

    public function query(): Builder
    {
        return RewardPointEntry::query()->with([...self::employeeWith('employee'), 'counterpart.person', 'creator'])->orderByDesc('id');
    }
}
