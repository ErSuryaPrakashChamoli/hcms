<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * HROne setup parity (TO-4): absenteeism alerts. An employee whose attendance policy sets `absence_alert_days` (N) and
 * who was absent on the last N working days up to yesterday — and present (or on leave) the working day before them —
 * raises `attendance.absence_alert` once for that run of absences. Who is told is configured as a notification rule.
 */
class SendAbsenceAlerts extends Command
{
    protected $signature = 'peopleos:attendance:absence-alerts {--date= : Check up to this day (default yesterday)} {--tenant=}';

    protected $description = 'Raise absenteeism alerts for employees absent N working days in a row';

    public function handle(TenantContext $tenants, PolicyResolver $policies): int
    {
        $runner = TenantRunner::for($this);
        $until = Carbon::parse($this->option('date') ?? now()->subDay())->startOfDay();

        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(function (Tenant $tenant) use ($tenants, $policies, $until) {
                $tenants->runAs($tenant, function () use ($tenant, $policies, $until) {
                    $alerts = 0;
                    Employee::query()->employed()->each(function (Employee $employee) use ($policies, $until, &$alerts) {
                        $days = (int) ($policies->resolve('attendance', $employee, $until)?->setting('absence_alert_days') ?? 0);
                        if ($days <= 0) {
                            return;
                        }
                        // The last N + 1 working days (off days are skipped), newest first.
                        $recent = AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', '<=', $until->toDateString())
                            ->whereNotIn('status', ['weekly_off', 'holiday'])->orderByDesc('date')->limit($days + 1)->get(['date', 'status']);
                        $run = $recent->take($days);
                        $before = $recent->get($days);
                        if ($run->count() === $days && $run->first()->date->isSameDay($until) && $run->every(fn ($r) => $r->status === 'absent') && $before?->status !== 'absent') {
                            AttendanceEvent::dispatch('attendance.absence_alert', $employee, $run->first(), ['days' => $days, 'from' => $run->last()->date->toDateString(), 'to' => $until->toDateString()]);
                            $alerts++;
                        }
                    });
                    $this->info("{$tenant->slug}: {$alerts} absenteeism alert(s)");
                });
            }));

        return $runner->exitCode();
    }
}
