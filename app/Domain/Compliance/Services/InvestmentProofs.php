<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\OwnRecords;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Investment proof submission and review (HROne parity PY-12). The employee attaches the proof of a declared item
 * and the amount it shows, in the organisation's proof window; payroll (compliance.tds.manage) verifies it for an
 * amount or rejects it with a note. A rejected proof can be submitted again; a verified one is final.
 */
final class InvestmentProofs
{
    public function __construct(private readonly TaxSettings $settings) {}

    /** @param  list<string>  $files  stored paths on the documents disk */
    public function submit(TdsEmployeeInvestment $item, array $files, float $amount, User $by): TdsEmployeeInvestment
    {
        $payroll = $by->hasPermission('payroll.manage');
        if (! $payroll && ! app(OwnRecords::class)->isOwn($by, $item->employee_id)) {
            throw new RuntimeException('You can submit proofs only for your own declaration.');
        }
        if (! $payroll && ! $this->settings->proofOpen()) {
            throw new RuntimeException('Proofs can be submitted '.$this->settings->proofWindow().'.');
        }
        if ($files === []) {
            throw new RuntimeException('Attach the proof.');
        }
        if ($amount <= 0) {
            throw new RuntimeException('Enter the amount the proof shows.');
        }

        return DB::transaction(function () use ($item, $files, $amount) {
            $item = TdsEmployeeInvestment::query()->lockForUpdate()->findOrFail($item->id);
            if ($item->proof_status === 'verified') {
                throw new RuntimeException('Payroll has verified this proof already.');
            }
            $item->fill(['proof_files' => array_values($files), 'proof_claimed_amount' => round($amount, 2), 'proof_submitted_at' => now()]);
            if ($item->proof_status === 'rejected') {
                $item->fill(['proof_status' => 'pending', 'proof_amount' => null, 'verified_by' => null, 'verified_at' => null]);
            }
            $item->withAuditReason('Investment proof submitted')->save();

            return $item;
        });
    }

    public function review(TdsEmployeeInvestment $item, string $status, float $amount, ?string $notes, User $by): TdsEmployeeInvestment
    {
        if (! $by->can('update', $item)) {
            throw new RuntimeException('You cannot review investment proofs.');
        }
        if (app(OwnRecords::class)->isOwn($by, $item->employee_id)) {
            throw new RuntimeException('You cannot verify your own investment proof.');
        }
        if (! in_array($status, ['verified', 'rejected'], true)) {
            throw new RuntimeException('Verify or reject the proof.');
        }
        if ($status === 'verified' && $amount <= 0) {
            throw new RuntimeException('Enter the amount you accept.');
        }
        $item->withAuditReason($notes ?: 'Proof reviewed')->update([
            'proof_status' => $status, 'proof_amount' => $status === 'verified' ? round($amount, 2) : null,
            'notes' => $notes, 'verified_by' => $by->id, 'verified_at' => now(),
        ]);

        return $item;
    }
}
