<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Jobs\ProcessAttendanceRange;
use App\Domain\Attendance\Models\AttendanceMonthRun;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Services\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne parity TX-3 (matrix 24 §24.5.3 #11, #13, #14): calculate a company's attendance month for the people chosen, in
 * the background. One ProcessAttendanceRange job per employee (their days of the month up to today, inside joining and
 * exit); each job moves the run's counters; the last one completes the run and tells the person who started it in the
 * app and by e-mail (notification event attendance.month_processed). Calculation itself is the processor's, unchanged:
 * days locked or in a closed month keep their values.
 */
final class AttendanceMonthRuns
{
    public const EVENT = 'attendance.month_processed';

    public function __construct(private readonly AttendanceMonthCloses $closes, private readonly AuditRecorder $audit) {}

    /**
     * @param  Collection<int, Employee>  $employees  the people to calculate (already narrowed in the starter's access scope)
     * @param  array<string, string>  $filters  what narrowed them, as labels (kept on the run)
     */
    public function start(int $companyId, int $year, int $month, Collection $employees, User $actor, array $filters = []): AttendanceMonthRun
    {
        if (! $actor->hasPermission('attendance.process')) {
            throw new RuntimeException('You cannot process attendance.');
        }
        [$start, $end] = $this->closes->window($companyId, $year, $month);
        $label = Carbon::create($year, $month, 1)->format('M Y');
        if ($start->gt(today())) {
            throw new RuntimeException("{$label} has not started yet.");
        }
        if ($this->closes->isClosed($companyId, $year, $month)) {
            throw new RuntimeException("{$label} is closed: its days keep their values. Reopen the month to process it again.");
        }
        $until = $end->min(CarbonImmutable::today());
        $jobs = [];
        foreach ($employees as $employee) {
            $from = $employee->joining_date !== null ? $start->max(CarbonImmutable::parse($employee->joining_date)->startOfDay()) : $start;
            $to = $employee->exit_date !== null ? $until->min(CarbonImmutable::parse($employee->exit_date)->startOfDay()) : $until;
            if ($from->lte($to)) {
                $jobs[(int) $employee->id] = [$from->toDateString(), $to->toDateString()];
            }
        }
        if ($jobs === []) {
            throw new RuntimeException("Nobody to process in {$label} with these choices.");
        }

        return DB::transaction(function () use ($companyId, $year, $month, $start, $end, $jobs, $actor, $filters, $label) {
            $run = new AttendanceMonthRun(['company_id' => $companyId, 'year' => $year, 'month' => $month, 'start_date' => $start, 'end_date' => $end,
                'status' => 'running', 'total' => count($jobs), 'done' => 0, 'failed' => 0, 'filters' => $filters ?: null, 'started_by' => $actor->id]);
            $run->withAuditReason("Attendance of {$label} queued for ".count($jobs).' employee(s)')->save();
            foreach ($jobs as $employeeId => [$from, $to]) {
                ProcessAttendanceRange::dispatch($employeeId, $from, $to, $run->id)->afterCommit();
            }

            return $run;
        });
    }

    /** One employee of the run reached the end (done, or failed for good after its retries). */
    public function tick(int $runId, bool $ok): void
    {
        $updated = AttendanceMonthRun::query()->whereKey($runId)->where('status', 'running')->increment($ok ? 'done' : 'failed');
        if ($updated === 0) {
            return;
        }
        $run = AttendanceMonthRun::query()->find($runId);
        if ($run === null || $run->done + $run->failed < $run->total) {
            return;
        }
        // Exactly one job completes the run (the status moves once), so the notice goes once.
        if (AttendanceMonthRun::query()->whereKey($runId)->where('status', 'running')->update(['status' => 'completed', 'finished_at' => now()]) !== 1) {
            return;
        }
        $run->refresh();
        $company = $run->company()->value('name');
        $summary = "{$run->done} of {$run->total} employee(s) processed".($run->failed > 0 ? ", {$run->failed} could not be processed (see the failed jobs)" : '').
            ' for '.$run->start_date->format('j M').' – '.$run->end_date->format('j M Y').'.';
        $this->audit->record(AuditAction::StatusChange, 'attendance', $run, [], "Attendance of {$run->label()} processed", metadata: ['done' => $run->done, 'failed' => $run->failed, 'total' => $run->total]);

        $starter = $run->started_by ? User::query()->find($run->started_by) : null;
        if ($starter !== null) {
            app(Notifier::class)->send([$starter], ['in_app', 'email'], "Attendance processed: {$run->label()}".($company ? " · {$company}" : ''), $summary, self::EVENT, $run);
        }
    }

    /** The latest runs of a company's month, newest first. @return Collection<int, AttendanceMonthRun> */
    public function latest(int $companyId, int $year, int $month, int $limit = 3): Collection
    {
        return AttendanceMonthRun::query()->with('starter:id,name')->where('company_id', $companyId)->where('year', $year)->where('month', $month)
            ->latest('id')->limit($limit)->get();
    }
}
