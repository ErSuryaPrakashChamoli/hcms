<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\OwnRecords;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Investment proof submission and review (HROne parity PY-12). The employee attaches the proof of a declared item
 * and the amount it shows, in the organisation's proof window; payroll (compliance.tds.manage) verifies it for an
 * amount or rejects it with a note. A rejected proof can be submitted again; a verified one is final. Payroll
 * (payroll.manage) may submit for an employee in its scope at any time — HROne parity AC-2: recorded as on their behalf.
 */
final class InvestmentProofs
{
    public function __construct(private readonly TaxSettings $settings) {}

    /** @param  list<string>  $files  stored paths on the documents disk */
    public function submit(TdsEmployeeInvestment $item, array $files, float $amount, User $by): TdsEmployeeInvestment
    {
        $payroll = $by->hasPermission('payroll.manage');
        $own = app(OwnRecords::class)->isOwn($by, $item->employee_id);
        if (! $payroll && ! $own) {
            throw new RuntimeException('You can submit proofs only for your own declaration.');
        }
        // HROne parity AC-2: payroll submits for an employee only within its payroll access scope.
        if (! $own && ! app(AccessScopes::class)->allowsEmployeeId($by, (int) $item->employee_id, 'payroll')) {
            throw new RuntimeException('You cannot submit proofs for this employee.');
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

        return DB::transaction(function () use ($item, $files, $amount, $by, $own) {
            $item = TdsEmployeeInvestment::query()->lockForUpdate()->findOrFail($item->id);
            if ($item->proof_status === 'verified') {
                throw new RuntimeException('Payroll has verified this proof already.');
            }
            $item->fill(['proof_files' => array_values($files), 'proof_claimed_amount' => round($amount, 2), 'proof_submitted_at' => now()]);
            if ($item->proof_status === 'rejected') {
                $item->fill(['proof_status' => 'pending', 'proof_amount' => null, 'verified_by' => null, 'verified_at' => null]);
            }
            $item->withAuditReason($own ? 'Investment proof submitted' : 'Investment proof submitted on behalf of the employee')->save();
            if (! $own) {
                app(AuditRecorder::class)->record(AuditAction::Submitted, 'compliance', $item, reason: 'Investment proof submitted on behalf of the employee', actor: $by,
                    metadata: ['on_behalf' => true, 'employee_id' => (int) $item->employee_id, 'financial_year' => (string) $item->financial_year]);
            }

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
        // HROne parity AC-2, maker-checker (decided 2026-11-01 by common HCM practice): whoever submitted the proof on the
        // employee's behalf does not also verify it (the latest submission's audit event names who).
        $submitted = AuditEvent::query()->where('entity_type', $item::class)->where('entity_id', (string) $item->getKey())
            ->where('action', AuditAction::Submitted->value)->latest('occurred_at')->latest('id')->first();
        if ($submitted !== null && (int) $submitted->actor_id === (int) $by->id && ($submitted->metadata['on_behalf'] ?? false)) {
            throw new RuntimeException('You submitted this proof for the employee, so someone else verifies it.');
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
