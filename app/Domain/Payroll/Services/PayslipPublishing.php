<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Events\PayrollEvent;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Streamline S2 (decision ST-2): payslips reach employees in their own step after payroll closes, so the month can be
 * checked (and reopened) before anyone sees it. `payroll.payslips.release` = manual (the default: Publish payslips) or
 * at_close (published as the month closes). Each published payslip fires payroll.payslip_published, which tells the
 * employee their payslip is ready.
 */
final class PayslipPublishing
{
    public const MANUAL = 'manual';

    public const AT_CLOSE = 'at_close';

    public function __construct(private readonly AuditRecorder $audit, private readonly SettingsRepository $settings) {}

    public function atClose(): bool
    {
        return $this->settings->get('payroll.payslips.release', self::MANUAL) === self::AT_CLOSE;
    }

    public function isPublished(PayrollRun $run): bool
    {
        return $run->payslips_published_at !== null;
    }

    public function publish(PayrollRun $run, ?User $actor = null): PayrollRun
    {
        [$run, $payslips] = DB::transaction(function () use ($run, $actor) {
            $run = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail()->loadMissing('period');
            if (! in_array($run->status, ['finalized', 'paid'], true)) {
                throw new RuntimeException('Close payroll before publishing its payslips.');
            }
            if ($run->payslips_published_at !== null) {
                throw new RuntimeException("The payslips of {$run->period->label()} are already published.");
            }
            $run->update(['payslips_published_at' => now(), 'payslips_published_by' => $actor?->id ?? auth()->id()]);
            $payslips = Payslip::query()->withoutGlobalScopes()->where('tenant_id', $run->tenant_id)
                ->whereIn('payroll_entry_id', PayrollEntry::query()->withoutGlobalScopes()->where('payroll_run_id', $run->id)->select('id'))->get();
            $this->audit->record(AuditAction::PayslipsPublished, 'payroll', $run, [['field' => 'payslips_published_at', 'before' => null, 'after' => $run->payslips_published_at->toIso8601String()]], null,
                actor: $actor, metadata: ['period' => $run->period->label(), 'payslips' => $payslips->count()]);

            return [$run->refresh(), $payslips];
        });

        foreach ($payslips as $payslip) {
            PayrollEvent::dispatch('payroll.payslip_published', $payslip, ['employee_id' => (int) $payslip->employee_id, 'period' => $run->period->label(), 'net' => (float) $payslip->get('totals.net', 0)]);
        }
        PayrollEvent::dispatch('payroll.payslips_published', $run, ['period' => $run->period->label(), 'payslips' => $payslips->count()]);

        return $run;
    }
}
