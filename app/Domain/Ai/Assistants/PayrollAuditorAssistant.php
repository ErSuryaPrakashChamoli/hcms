<?php

namespace App\Domain\Ai\Assistants;

use App\Domain\Ai\Services\AiAnswer;
use App\Domain\Ai\Services\PayrollAnomalyDetector;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayrollRun;

/** Payroll Auditor (§94): explains anomalies in the latest (or a named) run. Never changes payroll. */
final class PayrollAuditorAssistant implements Assistant
{
    public function __construct(private readonly PayrollAnomalyDetector $detector) {}

    public function key(): string
    {
        return 'payroll_auditor';
    }

    public function examples(): array
    {
        return ['Audit the latest payroll', 'Any duplicate bank accounts?', 'Who has unusual deductions?'];
    }

    public function answer(User $user, ?Employee $employee, string $question): AiAnswer
    {
        $run = PayrollRun::query()->with(['period', 'company'])->whereIn('status', ['calculated', 'validated', 'approved', 'finalized', 'paid'])->orderByDesc('id')->first();
        if ($run === null) {
            return AiAnswer::text('There is no calculated payroll run to audit yet.', 'no_run');
        }
        $findings = $this->detector->audit($run);
        $q = strtolower($question);
        $filtered = collect($findings)->filter(fn ($f) => ! str_contains($q, 'duplicate') || $f['type'] === 'duplicate_bank')->filter(fn ($f) => ! str_contains($q, 'deduction') || in_array($f['type'], ['deduction_share', 'tds_jump', 'negative_net'], true))->values();

        if ($filtered->isEmpty()) {
            return new AiAnswer("I audited {$run->period->label()} for {$run->company->name} ({$run->total('employees')} employees, net ".number_format($run->total('net'), 2).') and found nothing unusual against the configured thresholds.', [['label' => 'Payroll run '.$run->period->label()]], [['label' => 'Open run', 'url' => url('/admin/payroll-runs/'.$run->id)]], 'audit_clean', true, ['run' => $run->period->label()]);
        }
        $bySeverity = $filtered->groupBy('severity');
        $lines = $filtered->take(12)->map(fn ($f) => strtoupper($f['severity']).' · '.$f['employee'].': '.$f['message'])->all();
        $answer = sprintf("Audit of %s (%s): %d finding(s) — %d high, %d medium, %d low. These are system-generated indicators to review, not conclusions.\n- %s", $run->period->label(), $run->company->name, $filtered->count(), $bySeverity->get('high', collect())->count(), $bySeverity->get('medium', collect())->count(), $bySeverity->get('low', collect())->count(), implode("\n- ", $lines));

        return new AiAnswer($answer, [['label' => 'Payroll run '.$run->period->label(), 'detail' => 'Thresholds from config peopleos.ai.payroll_audit']], [['label' => 'Payroll Auditor', 'url' => url('/admin/payroll-auditor')], ['label' => 'Open run', 'url' => url('/admin/payroll-runs/'.$run->id)]], 'audit', true, ['findings' => $lines]);
    }
}
