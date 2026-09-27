<?php

namespace App\Domain\Bgv\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Bgv\Events\BgvCompleted;
use App\Domain\Bgv\Models\BgvCase;
use App\Domain\Bgv\Models\BgvCheck;
use App\Domain\Bgv\Providers\BgvProvider;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Notifications\Services\NotificationContext;
use App\Domain\Notifications\Services\NotificationEngine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Bgv
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Timeline $timeline,
        private readonly NotificationEngine $notifications,
        private readonly NotificationContext $context,
    ) {}

    /** @param  list<string>  $checkTypes */
    public function initiate(Employee $employee, array $checkTypes = [], string $provider = 'manual', bool $consentGiven = false, ?EmployeeDocument $consentDocument = null, ?string $notes = null): BgvCase
    {
        if (! $consentGiven && $consentDocument === null) {
            throw new RuntimeException('Background verification needs the employee\'s consent first.');
        }

        if ($employee->bgvCases()->whereIn('status', ['initiated', 'in_progress'])->exists()) {
            throw new RuntimeException('A verification case is already open for this employee.');
        }

        $checkTypes = $checkTypes ?: config('peopleos.bgv.default_checks');

        return DB::transaction(function () use ($employee, $checkTypes, $provider, $consentDocument, $notes) {
            $case = new BgvCase([
                'employee_id' => $employee->id,
                'provider' => $provider,
                'status' => 'initiated',
                'consent_given_at' => now(),
                'consent_document_id' => $consentDocument?->id,
                'initiated_by' => auth()->id(),
                'initiated_at' => now(),
                'notes' => $notes,
            ]);
            $case->withAuditReason($notes)->save();

            foreach ($checkTypes as $type) {
                BgvCheck::create(['bgv_case_id' => $case->id, 'type' => $type]);
            }

            $reference = $this->provider($provider)->initiate($case);

            if ($reference !== null) {
                $case->update(['external_reference' => $reference, 'status' => 'in_progress']);
            }

            $this->timeline->record($employee, 'bgv', 'Background verification initiated', now(), implode(', ', array_map(fn ($t) => config("peopleos.bgv.check_types.{$t}", $t), $checkTypes)), $case);
            $this->notifications->fire('bgv.initiated', $this->context->build($employee, ['bgv' => ['case' => $case->id, 'provider' => $provider]]), $case);

            return $case->refresh();
        });
    }

    public function recordCheck(BgvCheck $check, string $status, ?string $notes = null, ?EmployeeDocument $evidence = null): BgvCheck
    {
        if (! array_key_exists($status, BgvCheck::STATUSES)) {
            throw new RuntimeException("Unknown check status [{$status}].");
        }

        $check->withAuditReason($notes)->update([
            'status' => $status,
            'result_notes' => $notes,
            'evidence_document_id' => $evidence?->id ?? $check->evidence_document_id,
            'verified_by' => auth()->id(),
            'completed_at' => $check->isClosed() || in_array($status, ['clear', 'discrepancy', 'failed', 'skipped'], true) ? now() : null,
        ]);

        $case = $check->case()->with('employee')->firstOrFail();

        if ($case->status === 'initiated') {
            $case->update(['status' => 'in_progress']);
        }

        $this->evaluate($case);

        return $check;
    }

    /** Close the case once every check is closed; overall = worst individual result. */
    public function evaluate(BgvCase $case): BgvCase
    {
        $case->loadMissing('employee');
        $checks = $case->checks()->get();

        if ($checks->isEmpty() || $checks->contains(fn (BgvCheck $c) => ! $c->isClosed())) {
            return $case;
        }

        $overall = match (true) {
            $checks->contains('status', 'failed') => 'failed',
            $checks->contains('status', 'discrepancy') => 'discrepancy',
            default => 'clear',
        };

        $case->withAuditReason('All checks closed')->update(['status' => 'completed', 'overall_result' => $overall, 'completed_at' => now()]);
        $this->audit->record(AuditAction::Update, 'bgv', $case, changes: [['field' => 'overall_result', 'before' => 'pending', 'after' => $overall]]);
        $this->timeline->record($case->employee, 'bgv', 'Background verification completed: '.ucfirst($overall), now(), null, $case);
        $this->notifications->fire('bgv.completed', $this->context->build($case->employee, ['bgv' => ['case' => $case->id, 'result' => $overall]]), $case);
        BgvCompleted::dispatch($case);

        return $case;
    }

    public function close(BgvCase $case, string $reason): BgvCase
    {
        $case->checks()->where('status', 'pending')->orWhere('status', 'in_progress')->update(['status' => 'skipped', 'result_notes' => $reason, 'completed_at' => now()]);
        $case->withAuditReason($reason)->update(['status' => 'closed', 'completed_at' => now()]);

        return $case;
    }

    private function provider(string $key): BgvProvider
    {
        $driver = config("peopleos.bgv.providers.{$key}.driver") ?? throw new RuntimeException("Unknown BGV provider [{$key}].");

        return app($driver);
    }
}
