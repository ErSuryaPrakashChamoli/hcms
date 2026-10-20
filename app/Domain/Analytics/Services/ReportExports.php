<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Models\ReportRun;
use App\Domain\Analytics\Models\ReportSchedule;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Csv\Writer;

/** Runs a report to a stored CSV and records the run (§84 export + audit). */
final class ReportExports
{
    public function __construct(private readonly ReportRunner $runner, private readonly AuditRecorder $audit) {}

    public function csv(ReportResult $result): string
    {
        $writer = Writer::createFromString();
        $writer->setOutputBOM(Writer::BOM_UTF8);
        $writer->insertOne(array_values($result->columns));
        foreach ($result->rows as $row) {
            $writer->insertOne(array_map(fn ($key) => is_bool($row[$key] ?? null) ? ($row[$key] ? 'Yes' : 'No') : ($row[$key] ?? ''), array_keys($result->columns)));
        }

        return $writer->toString();
    }

    public function export(Report $report, ?User $user = null, ?ReportSchedule $schedule = null): ReportRun
    {
        $run = ReportRun::create(['report_id' => $report->id, 'report_schedule_id' => $schedule?->id, 'run_by' => $user?->id, 'format' => 'csv', 'status' => 'running', 'started_at' => now()]);

        try {
            $result = $this->runner->run($report, $user);
            $disk = config('peopleos.documents.disk', 'local');
            $path = "tenants/{$report->tenant_id}/reports/".Str::slug($report->name).'-'.now()->format('Ymd-His').'-'.$run->id.'.csv';
            Storage::disk($disk)->put($path, $this->csv($result));
            $run->update(['status' => 'completed', 'row_count' => $result->total, 'disk' => $disk, 'path' => $path, 'finished_at' => now()]);
            $this->audit->record(AuditAction::Export, 'analytics', $report, [], null, actor: $user, metadata: ['rows' => $result->total, 'run_id' => $run->id, 'scheduled' => $schedule !== null]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
        }

        return $run->refresh();
    }

    /**
     * Phase 14: a stored export holds what its runner could see (fields, sensitive values, organisation
     * scope). Only that runner, or the report owner for a scheduled run (which ran with the owner's
     * rights), may re-download it. Everyone else runs the report under their own rights.
     */
    public function canDownload(ReportRun $run, User $user): bool
    {
        if (! $run->hasFile() || ! ($user->hasPermission('analytics.export') || $user->hasPermission('analytics.manage'))) {
            return false;
        }

        return (int) $run->run_by === (int) $user->id || ($run->report_schedule_id !== null && (int) $run->report?->owner_id === (int) $user->id);
    }

    /** Re-download of a stored export: authorised as above and audited. */
    public function download(ReportRun $run, User $user): string
    {
        if (! $this->canDownload($run, $user)) {
            throw new \RuntimeException('Only the person who produced this export (or the report owner, for a scheduled run) may download it.');
        }
        $this->audit->record(AuditAction::Download, 'analytics', $run->report, [], null, actor: $user, metadata: ['run_id' => $run->id, 'rows' => $run->row_count, 'redownload' => true]);

        return (string) $this->contents($run);
    }

    public function contents(ReportRun $run): ?string
    {
        return $run->hasFile() ? Storage::disk($run->disk)->get($run->path) : null;
    }
}
