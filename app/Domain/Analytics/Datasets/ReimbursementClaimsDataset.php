<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Claims\Models\ReimbursementClaim;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity AN-26 (HROne report "Reimbursement request"): every reimbursement claim with its bills, the amount asked
 * and approved, and its status. Bill attachments stay on the claim (only their number is reported). For payroll
 * (payroll.manage) in the reader's access scope; amounts are sensitive fields of that right.
 */
class ReimbursementClaimsDataset extends Dataset
{
    public function key(): string
    {
        return 'reimbursement_claims';
    }

    public function label(): string
    {
        return 'Reimbursement claims';
    }

    public function category(): string
    {
        return 'Payroll & compensation';
    }

    public function permissions(): array
    {
        return ['payroll.manage'];
    }

    public function sensitivePermission(): ?string
    {
        return 'payroll.manage';
    }

    public function fields(): array
    {
        $money = fn (string $label, \Closure $value) => ['label' => $label, 'type' => 'number', 'sensitive' => true, 'value' => $value];

        return [
            'claim_date' => ['label' => 'Claim date', 'type' => 'date', 'value' => fn (ReimbursementClaim $c) => $c->claim_date],
            'financial_year' => ['label' => 'Financial year', 'type' => 'string', 'value' => fn (ReimbursementClaim $c) => ReimbursementLedgerDataset::yearLabel((int) $c->financial_year)],
            ...$this->employeeFields(fn (ReimbursementClaim $c) => $c->employee),
            'component' => ['label' => 'Reimbursement', 'type' => 'string', 'value' => fn (ReimbursementClaim $c) => $c->component?->name],
            'description' => ['label' => 'Description', 'type' => 'string', 'value' => fn (ReimbursementClaim $c) => $c->description],
            'amount' => $money('Amount claimed', fn (ReimbursementClaim $c) => round((float) $c->amount, 2)),
            'approved_amount' => $money('Amount approved', fn (ReimbursementClaim $c) => $c->approved_amount === null ? null : round((float) $c->approved_amount, 2)),
            'payable' => $money('Payable', fn (ReimbursementClaim $c) => in_array($c->status, ['rejected', 'cancelled'], true) ? 0.0 : round($c->payable(), 2)),
            'bills' => ['label' => 'Bills', 'type' => 'number', 'value' => fn (ReimbursementClaim $c) => count((array) ($c->bills ?? []))],
            'bills_total' => $money('Bills total', fn (ReimbursementClaim $c) => round((float) collect((array) ($c->bills ?? []))->sum(fn ($b) => is_array($b) ? (float) ($b['amount'] ?? 0) : 0), 2)),
            'attachments' => ['label' => 'Attachments', 'type' => 'number', 'value' => fn (ReimbursementClaim $c) => count((array) ($c->attachments ?? []))],
            'status' => ['label' => 'Status (code)', 'type' => 'string', 'value' => fn (ReimbursementClaim $c) => $c->status],
            'status_label' => ['label' => 'Status', 'type' => 'string', 'value' => fn (ReimbursementClaim $c) => ReimbursementClaim::STATUSES[$c->status] ?? $c->status],
            'requested_by' => ['label' => 'Raised by', 'type' => 'string', 'value' => fn (ReimbursementClaim $c) => $c->requester?->name],
            'reviewed_on' => ['label' => 'Decided on', 'type' => 'date', 'value' => fn (ReimbursementClaim $c) => $c->reviewed_at],
            'reviewed_by' => ['label' => 'Decided by', 'type' => 'string', 'value' => fn (ReimbursementClaim $c) => $c->reviewer?->name],
            'count' => ['label' => 'Claims (1 per row)', 'type' => 'number', 'value' => fn (ReimbursementClaim $c) => 1],
        ];
    }

    public function query(): Builder
    {
        return ReimbursementClaim::query()->with([...self::employeeWith('employee'), 'component', 'requester', 'reviewer'])->orderByDesc('claim_date')->orderByDesc('id');
    }
}
