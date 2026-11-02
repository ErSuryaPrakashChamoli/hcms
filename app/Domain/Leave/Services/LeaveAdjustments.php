<?php

namespace App\Domain\Leave\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Events\LeaveEvent;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\PayMonth;
use App\Support\Storage\FileSafety;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Manual adjustments, comp-off credits and encashment (§25). */
final class LeaveAdjustments
{
    /** Files kept per encashment request. */
    public const MAX_FILES = 5;

    /** The documents disk's upload area of the encashment form (files move to the tenant's folder when the request is made). */
    public const UPLOAD_AREA = 'leave-encashments/';

    public function __construct(
        private readonly LeaveBalances $balances,
        private readonly LeaveEntitlements $entitlements,
        private readonly LeaveYear $years,
        private readonly AuditRecorder $audit,
    ) {}

    public function adjust(Employee $employee, LeaveType $type, float $days, string $note, ?int $period = null, string $entryType = 'adjustment'): LeaveLedgerEntry
    {
        if ($days === 0.0) {
            throw new RuntimeException('Adjustment must not be zero.');
        }

        $period ??= $this->years->periodFor(now());
        $entry = $this->balances->post($employee, $type, $period, $entryType, $days, null, $note);

        $this->audit->record(AuditAction::LeaveBalanceAdjusted, 'leave', $entry, changes: [['field' => "{$type->code} balance ({$period})", 'before' => null, 'after' => sprintf('%+.2f', $days)]], reason: $note, entityLabel: $employee->auditLabel().' · '.$type->code, metadata: ['employee_id' => $employee->id, 'entry_type' => $entryType]);
        LeaveEvent::dispatch('leave.balance_adjusted', $employee, $entry, ['leave_type' => $type->code, 'days' => $days, 'note' => $note]);

        return $entry;
    }

    /**
     * Opening balance for a period (go-live or migration), idempotent per employee/type/period:
     * a second call returns null instead of doubling the balance.
     */
    public function opening(Employee $employee, LeaveType $type, float $days, int $period, string $note): ?LeaveLedgerEntry
    {
        if ($days < 0) {
            throw new RuntimeException('An opening balance cannot be negative; use an adjustment.');
        }

        $entry = $this->balances->post($employee, $type, $period, 'opening', $days, null, $note, "opening-{$period}", $this->years->start($period)->toDateString());

        if ($entry !== null) {
            $this->audit->record(AuditAction::LeaveBalanceAdjusted, 'leave', $entry, changes: [['field' => "{$type->code} opening ({$period})", 'before' => null, 'after' => sprintf('%.2f', $days)]], reason: $note, entityLabel: $employee->auditLabel().' · '.$type->code, metadata: ['employee_id' => $employee->id, 'entry_type' => 'opening']);
        }

        return $entry;
    }

    public function creditCompOff(Employee $employee, float $days, string $note): LeaveLedgerEntry
    {
        $type = LeaveType::query()->where('category', 'comp_off')->where('status', 'active')->first() ?? throw new RuntimeException('No compensatory-off leave type is configured.');

        return $this->adjust($employee, $type, $days, $note, null, 'comp_off_credit');
    }

    /**
     * @param  list<string>  $attachments  HROne parity RQ-4: supporting files uploaded to the documents disk's `leave-encashments/`
     *                                     upload area (required when the leave type's rule `encash_document_required` says so)
     * @param  array<string, string>  $names  upload path => the file's original name (its extension is checked)
     * @param  string|null  $payMonth  HROne parity PO-8: 'YYYY-MM' of the pay run that pays it (null = the next one, as before)
     */
    public function requestEncashment(Employee $employee, LeaveType $type, float $days, ?string $reason = null, ?int $period = null, array $attachments = [], array $names = [], ?string $payMonth = null): LeaveEncashment
    {
        $uploads = array_values(array_filter(array_map('strval', $attachments), fn (string $p) => $p !== ''));
        try {
            $payMonth = PayMonth::parse($payMonth);
            [$period, $files] = $this->checkEncashment($employee, $type, $days, $period, $uploads, $names);
        } catch (RuntimeException $e) {
            $this->discardUploads($uploads); // a refused request leaves no file behind
            throw $e;
        }

        try {
            $encashment = new LeaveEncashment(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'period_year' => $period, 'days' => $days, 'status' => 'pending', 'reason' => $reason, 'requested_by' => auth()->id()]
                + ($files !== [] ? ['attachments' => $files] : []) + ($payMonth !== null ? ['pay_month' => $payMonth] : []));
            $encashment->withAuditReason($reason)->save();
        } catch (Throwable $e) {
            $this->documentsDisk()->delete($files);
            throw $e;
        }
        LeaveEvent::dispatch('leave.encashment_requested', $employee, $encashment, ['leave_type' => $type->code, 'days' => $days]);

        return $encashment;
    }

    /**
     * The checks of requestEncashment(), then the supporting files moved into the tenant's folder.
     *
     * @param  list<string>  $uploads
     * @param  array<string, string>  $names
     * @return array{0: int, 1: list<string>}
     */
    private function checkEncashment(Employee $employee, LeaveType $type, float $days, ?int $period, array $uploads, array $names): array
    {
        $period ??= $this->years->periodFor(now());
        $rule = $this->entitlements->forType($employee, $type) ?? throw new RuntimeException("No leave policy grants {$type->name}.");

        if (! $type->is_encashable || ! $rule['encashment_allowed']) {
            throw new RuntimeException("{$type->name} cannot be encashed.");
        }

        $alreadyRequested = (float) LeaveEncashment::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('period_year', $period)->whereIn('status', ['pending', 'approved', 'paid'])->sum('days');

        if ((int) $rule['max_encash_days'] > 0 && $alreadyRequested + $days > (int) $rule['max_encash_days']) {
            throw new RuntimeException("At most {$rule['max_encash_days']} day(s) may be encashed per year.");
        }

        if ($days <= 0 || $days > $this->balances->balance($employee, $type, $period)->available()) {
            throw new RuntimeException('Not enough balance to encash.');
        }
        // HROne setup parity (TO-7): most days per request and the balance to keep.
        if (($perRequest = (float) ($rule['max_encash_per_request'] ?? 0)) > 0 && $days > $perRequest) {
            throw new RuntimeException("At most {$perRequest} day(s) can be encashed in one request.");
        }
        $keep = (float) ($rule['min_balance_after_encash'] ?? 0);
        if ($keep > 0 && $this->balances->balance($employee, $type, $period)->available() - $days < $keep) {
            throw new RuntimeException("Keep at least {$keep} day(s) after encashment.");
        }
        // HROne parity RQ-4: a supporting file when the leave type's encashment rule asks for one.
        if (! empty($rule['encash_document_required']) && $uploads === []) {
            throw new RuntimeException("Attach a supporting file to encash {$type->name}.");
        }

        return [$period, $this->storeFiles($employee, $uploads, $names)];
    }

    /**
     * HROne parity RQ-4 (matrix 20 §20.1.18 Encashment #3, #5): what the encashment form shows before anything is asked
     * for — the balance available now, the leave type's encashment rule (TO-7: days a year and how many are already asked
     * for this year, most days in one request, the balance to keep, whether a supporting file is required) and the most
     * that can be asked for now, in half days. Read only: requestEncashment() still decides.
     *
     * @return array{allowed: bool, reason: ?string, available: float, year_limit: ?float, already: float, per_request: ?float, keep: float, max_now: float, needs_file: bool}
     */
    public function encashmentAllowance(Employee $employee, LeaveType $type, ?int $period = null): array
    {
        $period ??= $this->years->periodFor(now());
        $rule = $this->entitlements->forType($employee, $type);
        $available = round($this->balances->balance($employee, $type, $period)->available(), 2);
        $already = round((float) LeaveEncashment::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('period_year', $period)->whereIn('status', ['pending', 'approved', 'paid'])->sum('days'), 2);
        $yearLimit = $rule !== null && (int) $rule['max_encash_days'] > 0 ? (float) (int) $rule['max_encash_days'] : null;
        $perRequest = $rule !== null && ($p = (float) ($rule['max_encash_per_request'] ?? 0)) > 0 ? $p : null;
        $keep = $rule !== null ? max(0.0, (float) ($rule['min_balance_after_encash'] ?? 0)) : 0.0;
        $reason = match (true) {
            $rule === null => "No leave policy grants {$type->name}.",
            ! $type->is_encashable || ! $rule['encashment_allowed'] => "{$type->name} cannot be encashed.",
            default => null,
        };
        $max = $reason !== null ? 0.0 : min($available - $keep, $yearLimit !== null ? $yearLimit - $already : INF, $perRequest ?? INF);

        return [
            'allowed' => $reason === null, 'reason' => $reason, 'available' => $available, 'year_limit' => $yearLimit, 'already' => $already,
            'per_request' => $perRequest, 'keep' => $keep, 'max_now' => max(0.0, floor($max * 2) / 2),
            'needs_file' => $rule !== null && ! empty($rule['encash_document_required']),
        ];
    }

    /**
     * Check every upload (in the upload area, on the document allowlist, within the size limit, not active content), then
     * move them to tenants/{tenant}/leave-encashments/{employee}/.
     *
     * @param  list<string>  $uploads
     * @param  array<string, string>  $names
     * @return list<string>
     */
    private function storeFiles(Employee $employee, array $uploads, array $names): array
    {
        if (count($uploads) > self::MAX_FILES) {
            throw new RuntimeException('Attach at most '.self::MAX_FILES.' files.');
        }
        $disk = $this->documentsDisk();
        $checked = [];
        foreach ($uploads as $upload) {
            $source = ltrim(str_replace(['\\', '..'], ['/', ''], $upload), '/');
            if (! str_starts_with($source, self::UPLOAD_AREA) || ! $disk->exists($source)) {
                throw new RuntimeException('The supporting file was not found. Upload it again.');
            }
            $checked[$source] = FileSafety::assertAllowed((string) ($names[$upload] ?? basename($source)), (int) $disk->size($source), FileSafety::sniff((string) $disk->get($source)));
        }
        $files = [];
        foreach ($checked as $source => $extension) {
            $path = "tenants/{$employee->tenant_id}/leave-encashments/{$employee->id}/".Str::ulid().'.'.$extension;
            $disk->move($source, $path);
            $files[] = $path;
        }

        return $files;
    }

    /** @param  list<string>  $uploads  only files still in the upload area are removed */
    private function discardUploads(array $uploads): void
    {
        $disk = $this->documentsDisk();
        foreach ($uploads as $upload) {
            $source = ltrim(str_replace(['\\', '..'], ['/', ''], $upload), '/');
            if (str_starts_with($source, self::UPLOAD_AREA)) {
                $disk->delete($source);
            }
        }
    }

    private function documentsDisk(): Filesystem
    {
        return Storage::disk(config('peopleos.documents.disk', 'local'));
    }

    /** @param  string|null  $payMonth  HROne parity PO-8: 'YYYY-MM' of the pay run that pays it (null = as requested, else the next run) */
    public function approveEncashment(LeaveEncashment $encashment, ?string $note = null, ?string $payMonth = null): LeaveEncashment
    {
        if ($encashment->status !== 'pending') {
            throw new RuntimeException('This encashment has already been decided.');
        }
        $payMonth = PayMonth::parse($payMonth);

        $encashment->loadMissing(['employee', 'leaveType']);

        return DB::transaction(function () use ($encashment, $note, $payMonth) {
            $encashment->withAuditReason($note)->update(['status' => 'approved', 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'review_note' => $note]
                + ($payMonth !== null ? ['pay_month' => $payMonth] : []));
            $this->balances->post($encashment->employee, $encashment->leaveType, $encashment->period_year, 'encashment', -(float) $encashment->days, $encashment, $note ?? 'Encashment approved');
            $this->audit->record(AuditAction::Approved, 'leave', $encashment, reason: $note);
            LeaveEvent::dispatch('leave.encashment_approved', $encashment->employee, $encashment, ['leave_type' => $encashment->leaveType->code, 'days' => (float) $encashment->days]);

            return $encashment;
        });
    }

    public function rejectEncashment(LeaveEncashment $encashment, string $note): LeaveEncashment
    {
        if ($encashment->status !== 'pending') {
            throw new RuntimeException('This encashment has already been decided.');
        }

        $encashment->withAuditReason($note)->update(['status' => 'rejected', 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'review_note' => $note]);
        $this->audit->record(AuditAction::Rejected, 'leave', $encashment, reason: $note);
        $encashment->loadMissing('employee');
        LeaveEvent::dispatch('leave.encashment_rejected', $encashment->employee, $encashment, ['note' => $note]);

        return $encashment;
    }

    /**
     * HP-1: withdraw a pending encashment, or cancel an approved one that has not been paid: the encashed days go
     * back to the balance through a reversing ledger entry.
     */
    public function cancelEncashment(LeaveEncashment $encashment, string $reason): LeaveEncashment
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason for cancelling.');
        }

        return DB::transaction(function () use ($encashment, $reason) {
            $locked = LeaveEncashment::query()->whereKey($encashment->getKey())->lockForUpdate()->firstOrFail();
            $locked->load(['employee', 'leaveType']);

            if (! in_array($locked->status, ['pending', 'approved'], true)) {
                throw new RuntimeException($locked->status === 'paid' ? 'This encashment has already been paid.' : 'This encashment cannot be cancelled.');
            }
            if ($locked->status === 'approved') {
                $this->balances->post($locked->employee, $locked->leaveType, $locked->period_year, 'encashment', (float) $locked->days, $locked, 'Encashment cancelled: '.$reason, "encashment-{$locked->id}-reversal");
            }

            $was = $locked->status;
            $locked->withAuditReason($reason)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => auth()->id(), 'cancel_reason' => $reason]);
            $this->audit->record(AuditAction::Cancelled, 'leave', $locked, reason: $reason, metadata: ['was' => $was]);
            LeaveEvent::dispatch('leave.encashment_cancelled', $locked->employee, $locked, ['days' => (float) $locked->days, 'was' => $was]);

            return $locked;
        });
    }
}
