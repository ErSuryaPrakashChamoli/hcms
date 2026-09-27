<?php

namespace App\Domain\Compliance\Services\Returns;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compliance\Contracts\StatutoryReturnGenerator;
use App\Domain\Compliance\Models\StatutoryReconciliation;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Models\StatutoryReturnAction;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Statutory output lifecycle (Phase 5 Parts N–R).
 *
 *   DRAFT → CALCULATED → VALIDATED → APPROVED → EXPORTED → SUBMITTED → ACKNOWLEDGED → RECONCILED
 *                  └──→ RECONCILIATION_REQUIRED (payroll or filing mismatch)
 *   APPROVED+ → REVISED (when a revision is approved) · pre-submission → CANCELLED
 *
 * Separation of duties: the approver is never the generator; the person recording submission is
 * neither the generator nor the approver. EXPORTED never implies SUBMITTED, and nothing is ever
 * marked filed automatically: SUBMITTED needs an external reference entered by a person.
 */
final class StatutoryReturns
{
    public function __construct(private readonly AuditRecorder $audit, private readonly ReturnGenerators $generators) {}

    public function generator(StatutoryReturn $return): StatutoryReturnGenerator
    {
        return $this->generators->for($return->return_type);
    }

    /**
     * Create (or rebuild while still editable) the return identified by its uniqueness key.
     *
     * @param  array<string, mixed>  $header
     */
    public function generate(array $header, User $actor, string $source = 'ui', ?string $reason = null): StatutoryReturn
    {
        $this->requirePermission($actor, 'compliance.returns.generate');
        $key = $this->uniquenessKey($header);

        return DB::transaction(function () use ($header, $actor, $source, $reason, $key) {
            $return = StatutoryReturn::query()->where('uniqueness_key', $key)->lockForUpdate()->first();

            if ($return !== null && ! $return->isEditable()) {
                throw new RuntimeException("{$return->label()} is already {$return->status}; create a revision instead of regenerating it.");
            }

            $from = $return?->status;
            $return ??= StatutoryReturn::query()->create($header + ['uniqueness_key' => $key, 'status' => StatutoryReturn::DRAFT]);

            $return->fill(['generated_by' => $actor->getKey(), 'generated_at' => now(), 'operation_id' => Context::get('audit.operation_id'), 'reason' => $reason ?? $return->reason]);
            $return->save();

            $this->generator($return)->build($return);
            $return->refresh()->update(['status' => StatutoryReturn::CALCULATED, 'validation' => null, 'blocking_count' => 0, 'warning_count' => 0, 'reconciliation_status' => null, 'validated_at' => null]);

            $this->act($return, 'generated', $from, StatutoryReturn::CALCULATED, $actor, $reason, $source, AuditAction::StatutoryOutputCreated, ['entries' => $return->totals['entries'] ?? null]);

            return $return;
        });
    }

    /** Validation + payroll reconciliation. VALIDATED only with zero blocking issues and a balanced reconciliation. */
    public function validate(StatutoryReturn $return, User $actor, string $source = 'ui'): StatutoryReturn
    {
        $this->requirePermission($actor, 'compliance.returns.generate');
        $this->requireStatus($return, [StatutoryReturn::CALCULATED, StatutoryReturn::VALIDATED, StatutoryReturn::RECONCILIATION_REQUIRED]);

        return DB::transaction(function () use ($return, $actor, $source) {
            $generator = $this->generator($return);
            $issues = $generator->validate($return);
            $checks = $generator->reconcile($return);
            $reconciliationBlocking = collect($checks)->where('blocking', true)->count();

            StatutoryReconciliation::query()->create([
                'statutory_return_id' => $return->getKey(), 'stage' => 'payroll', 'status' => $reconciliationBlocking === 0 ? 'balanced' : 'exceptions',
                'checks' => $checks, 'blocking_count' => $reconciliationBlocking, 'performed_by' => $actor->getKey(), 'performed_at' => now(),
            ]);

            $blocking = collect($issues)->where('severity', 'blocking')->count();
            $status = match (true) {
                $reconciliationBlocking > 0 => StatutoryReturn::RECONCILIATION_REQUIRED,
                $blocking > 0 => StatutoryReturn::CALCULATED,
                default => StatutoryReturn::VALIDATED,
            };
            $from = $return->status;

            $return->update([
                'validation' => $issues, 'blocking_count' => $blocking + $reconciliationBlocking, 'warning_count' => collect($issues)->where('severity', 'warning')->count(),
                'reconciliation_status' => $reconciliationBlocking === 0 ? 'balanced' : 'exceptions', 'status' => $status,
                'validated_at' => $status === StatutoryReturn::VALIDATED ? now() : null,
            ]);

            $this->act($return, 'validated', $from, $status, $actor, null, $source, AuditAction::StatutoryOutputValidated, ['blocking' => $return->blocking_count, 'warnings' => $return->warning_count]);

            return $return;
        });
    }

    public function approve(StatutoryReturn $return, User $approver, ?string $reason = null, string $source = 'ui'): StatutoryReturn
    {
        $this->requirePermission($approver, 'compliance.returns.approve');

        return DB::transaction(function () use ($return, $approver, $reason, $source) {
            $return = StatutoryReturn::query()->whereKey($return->getKey())->lockForUpdate()->firstOrFail();
            $this->requireStatus($return, [StatutoryReturn::VALIDATED]);

            if ((int) $return->generated_by === (int) $approver->getKey()) {
                throw new RuntimeException('Separation of duties: the person who generated a return cannot approve it.');
            }
            if ($return->blocking_count > 0 || $return->reconciliation_status !== 'balanced') {
                throw new RuntimeException('A return with blocking issues or an unbalanced reconciliation cannot be approved.');
            }

            $return->update(['status' => StatutoryReturn::APPROVED, 'approved_by' => $approver->getKey(), 'approved_at' => now()]);
            $snapshots = $this->generator($return)->captureSnapshots($return);
            $this->act($return, 'approved', StatutoryReturn::VALIDATED, StatutoryReturn::APPROVED, $approver, $reason, $source, AuditAction::StatutoryOutputApproved, ['snapshots' => $snapshots]);

            // An approved revision replaces what it revises.
            if ($return->parent_return_id && $return->return_kind === 'revised' && ($parent = StatutoryReturn::query()->find($return->parent_return_id)) && $parent->status !== StatutoryReturn::REVISED) {
                $parentFrom = $parent->status;
                $parent->update(['status' => StatutoryReturn::REVISED]);
                $this->act($parent, 'revised', $parentFrom, StatutoryReturn::REVISED, $approver, 'Replaced by '.$return->label(), $source, AuditAction::StatutoryOutputRevised, ['revised_by_return_id' => $return->getKey()]);
            }

            return $return;
        });
    }

    /** Render and store the export file. Allowed from APPROVED onwards; never changes content. */
    public function export(StatutoryReturn $return, User $actor, string $source = 'ui'): StatutoryReturn
    {
        $this->requirePermission($actor, 'compliance.returns.export');
        $this->requireStatus($return, [StatutoryReturn::APPROVED, StatutoryReturn::EXPORTED, StatutoryReturn::SUBMITTED, StatutoryReturn::ACKNOWLEDGED, StatutoryReturn::RECONCILED]);

        $file = $this->generator($return)->export($return);
        $path = "statutory-exports/{$return->tenant_id}/{$return->getKey()}/{$file['filename']}";
        Storage::disk('local')->put($path, $file['content']);
        $checksum = hash('sha256', $file['content']);

        if ($return->export_checksum !== null && $return->export_checksum !== $checksum) {
            throw new RuntimeException('The export differs from the one already produced for this approved return; the content must not change after approval.');
        }

        $from = $return->status;
        $return->update([
            'status' => $return->status === StatutoryReturn::APPROVED ? StatutoryReturn::EXPORTED : $return->status,
            'exported_by' => $actor->getKey(), 'exported_at' => now(),
            'export_filename' => $file['filename'], 'export_path' => $path, 'export_checksum' => $checksum,
        ]);
        $this->act($return, 'exported', $from, $return->status, $actor, null, $source, AuditAction::StatutoryOutputExported, ['filename' => $file['filename'], 'checksum' => $checksum, 'format' => $return->format_code, 'format_version' => $return->format_version, 'format_verification_status' => $return->format_verification_status]);

        return $return;
    }

    public function exportContent(StatutoryReturn $return, User $actor, string $source = 'ui'): string
    {
        $this->requirePermission($actor, 'compliance.returns.export');

        if ($return->export_path === null || ! Storage::disk('local')->exists($return->export_path)) {
            throw new RuntimeException('Export the return first.');
        }

        $this->recordAccess($return, $actor, $source, 'export_download');

        return (string) Storage::disk('local')->get($return->export_path);
    }

    /** A person records that the exported file was filed on the portal, with the portal's reference. */
    public function recordSubmission(StatutoryReturn $return, User $actor, string $externalReference, Carbon|string $submittedAt, ?string $reason = null, string $source = 'ui'): StatutoryReturn
    {
        $this->requirePermission($actor, 'compliance.returns.file');
        $this->requireStatus($return, [StatutoryReturn::EXPORTED]);

        if (blank(trim($externalReference))) {
            throw new RuntimeException('Submission needs the external reference issued by the portal (e.g. TRRN or acknowledgement number).');
        }
        if (in_array((int) $actor->getKey(), [(int) $return->generated_by, (int) $return->approved_by], true)) {
            throw new RuntimeException('Separation of duties: filing confirmation must be recorded by someone other than the generator and the approver.');
        }
        if (Carbon::parse($submittedAt)->isFuture()) {
            throw new RuntimeException('The submission date cannot be in the future.');
        }

        $return->update(['status' => StatutoryReturn::SUBMITTED, 'submitted_by' => $actor->getKey(), 'submitted_at' => Carbon::parse($submittedAt), 'external_reference' => trim($externalReference)]);
        $this->act($return, 'submitted', StatutoryReturn::EXPORTED, StatutoryReturn::SUBMITTED, $actor, $reason, $source, AuditAction::StatutoryOutputSubmitted, ['external_reference' => trim($externalReference)]);

        return $return;
    }

    public function recordAcknowledgement(StatutoryReturn $return, User $actor, string $reference, Carbon|string $acknowledgedAt, string $source = 'ui'): StatutoryReturn
    {
        $this->requirePermission($actor, 'compliance.returns.file');
        $this->requireStatus($return, [StatutoryReturn::SUBMITTED]);

        if (blank(trim($reference))) {
            throw new RuntimeException('Acknowledgement needs the reference issued by the authority.');
        }

        $return->update(['status' => StatutoryReturn::ACKNOWLEDGED, 'acknowledged_by' => $actor->getKey(), 'acknowledged_at' => Carbon::parse($acknowledgedAt), 'acknowledgement_reference' => trim($reference)]);
        $this->act($return, 'acknowledged', StatutoryReturn::SUBMITTED, StatutoryReturn::ACKNOWLEDGED, $actor, null, $source, AuditAction::StatutoryOutputAcknowledged, ['acknowledgement_reference' => trim($reference)]);

        return $return;
    }

    /**
     * Post-filing reconciliation: the amounts the authority acknowledged against the return totals.
     *
     * @param  array<string, float|int>  $acknowledged  total key => amount as acknowledged
     */
    public function reconcileFiling(StatutoryReturn $return, User $actor, array $acknowledged, string $source = 'ui'): StatutoryReturn
    {
        $this->requirePermission($actor, 'compliance.reconcile');
        $this->requireStatus($return, [StatutoryReturn::ACKNOWLEDGED, StatutoryReturn::RECONCILIATION_REQUIRED]);

        if ($return->status === StatutoryReturn::RECONCILIATION_REQUIRED && $return->acknowledged_at === null) {
            throw new RuntimeException('This return needs payroll reconciliation (regenerate and validate), not filing reconciliation.');
        }
        if ($acknowledged === []) {
            throw new RuntimeException('Enter the acknowledged amounts to reconcile against.');
        }

        $checks = [];
        foreach ($acknowledged as $key => $amount) {
            $expected = round((float) data_get($return->totals, $key, 0), 2);
            $difference = round((float) $amount - $expected, 2);
            $checks[] = ['check' => "filing.{$key}", 'expected' => $expected, 'actual' => round((float) $amount, 2), 'difference' => $difference, 'blocking' => abs($difference) > 0.009];
        }
        $blocking = collect($checks)->where('blocking', true)->count();

        StatutoryReconciliation::query()->create(['statutory_return_id' => $return->getKey(), 'stage' => 'filing', 'status' => $blocking === 0 ? 'balanced' : 'exceptions', 'checks' => $checks, 'blocking_count' => $blocking, 'performed_by' => $actor->getKey(), 'performed_at' => now()]);

        $from = $return->status;
        $to = $blocking === 0 ? StatutoryReturn::RECONCILED : StatutoryReturn::RECONCILIATION_REQUIRED;
        $return->update(['status' => $to, 'reconciliation_status' => $blocking === 0 ? 'balanced' : 'exceptions', 'reconciled_at' => $blocking === 0 ? now() : null]);
        $this->act($return, 'reconciled', $from, $to, $actor, null, $source, AuditAction::StatutoryOutputReconciled, ['blocking' => $blocking]);

        return $return;
    }

    public function cancel(StatutoryReturn $return, User $actor, string $reason, string $source = 'ui'): StatutoryReturn
    {
        $this->requirePermission($actor, 'compliance.returns.generate');
        $this->requireStatus($return, [StatutoryReturn::DRAFT, StatutoryReturn::CALCULATED, StatutoryReturn::VALIDATED, StatutoryReturn::RECONCILIATION_REQUIRED, StatutoryReturn::APPROVED, StatutoryReturn::EXPORTED]);

        if ($return->status === StatutoryReturn::RECONCILIATION_REQUIRED && $return->submitted_at !== null) {
            throw new RuntimeException('A filed return cannot be cancelled; file a revision.');
        }
        if (blank(trim($reason))) {
            throw new RuntimeException('A reason is required to cancel a return.');
        }

        $from = $return->status;
        $return->update(['status' => StatutoryReturn::CANCELLED, 'cancelled_at' => now(), 'uniqueness_key' => null, 'reason' => $reason]);
        $this->act($return, 'cancelled', $from, StatutoryReturn::CANCELLED, $actor, $reason, $source, AuditAction::StatutoryOutputCancelled);

        return $return;
    }

    /** STATUTORY_OUTPUT_ACCESSED: reading entries or files of a return. */
    public function recordAccess(StatutoryReturn $return, ?User $actor, string $source, string $what): void
    {
        $this->act($return, 'accessed', $return->status, $return->status, $actor, null, $source, AuditAction::StatutoryOutputAccessed, ['what' => $what]);
    }

    /** @param  array<string, mixed>  $metadata */
    public function act(StatutoryReturn $return, string $action, ?string $from, ?string $to, ?User $actor, ?string $reason, string $source, AuditAction $auditAction, array $metadata = []): StatutoryReturnAction
    {
        $version = hash('sha256', json_encode([$return->getKey(), $return->sequence, $return->rule_versions, $return->totals]) ?: '');

        $this->audit->record(
            action: $auditAction,
            module: 'compliance',
            entity: $return,
            changes: $from !== $to ? [['field' => 'status', 'before' => $from, 'after' => $to, 'sensitive' => false]] : [],
            reason: $reason,
            metadata: $metadata + ['return_type' => $return->return_type, 'period' => $return->period_key, 'establishment_id' => $return->establishment_id, 'legal_entity_id' => $return->legal_entity_id, 'source' => $source, 'version' => $version],
            entityLabel: $return->label(),
            actor: $actor,
        );

        return StatutoryReturnAction::query()->create([
            'statutory_return_id' => $return->getKey(),
            'company_id' => $return->company_id,
            'establishment_id' => $return->establishment_id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $actor?->getKey(),
            'reason' => $reason,
            'source' => $source,
            'version' => $version,
            'metadata' => $metadata ?: null,
            'created_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $header */
    public function uniquenessKey(array $header): string
    {
        $scope = $header['establishment_id'] ?? ('le'.$header['legal_entity_id']);

        return implode('|', [$header['return_type'], $scope, $header['state_code'] ?? '-', $header['period_key'], $header['return_kind'] ?? 'regular', $header['sequence'] ?? 1]);
    }

    private function requirePermission(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new RuntimeException("You do not have the {$permission} permission.");
        }
    }

    /** @param  list<string>  $allowed */
    private function requireStatus(StatutoryReturn $return, array $allowed): void
    {
        if (! in_array($return->status, $allowed, true)) {
            throw new RuntimeException("{$return->label()} is {$return->status}; this step needs ".implode(' or ', $allowed).'.');
        }
    }
}
