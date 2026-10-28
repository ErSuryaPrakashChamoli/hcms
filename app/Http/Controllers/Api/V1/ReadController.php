<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Assets\Models\Asset;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\Goal;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Read API (§87): paginated, tenant-scoped by the API key, filtered with simple query parameters. */
class ReadController extends Controller
{
    use PaginatesApi;

    public function payrollRuns(Request $request): JsonResponse
    {
        $query = PayrollRun::query()->with(['period', 'company'])->whereIn('status', ['finalized', 'paid'])->orderByDesc('id');

        return $this->page($query, $request, fn (PayrollRun $r) => ['id' => $r->id, 'company' => $r->company?->code, 'period' => $r->period?->key(), 'status' => $r->status, 'totals' => $r->totals, 'finalized_at' => $r->finalized_at?->toIso8601String()]);
    }

    public function payrollPeriods(Request $request): JsonResponse
    {
        $query = PayrollPeriod::query()->with('company:id,code')->orderByDesc('start_date');

        return $this->page($query, $request, fn ($p) => ['id' => $p->id, 'company' => $p->company?->code, 'period' => $p->key(), 'start_date' => $p->start_date->toDateString(), 'end_date' => $p->end_date->toDateString(), 'status' => $p->status]);
    }

    public function payrollRun(int $run): JsonResponse
    {
        $r = PayrollRun::query()->with(['period', 'company'])->findOrFail($run);

        return response()->json(['data' => ['id' => $r->id, 'company' => $r->company?->code, 'period' => $r->period?->key(), 'status' => $r->status, 'calculation_version' => $r->calculation_version, 'totals' => $r->totals, 'rule_versions' => $r->rule_versions, 'reconciliation' => $r->reconciliation, 'exception_count' => $r->exception_count, 'finalized_at' => $r->finalized_at?->toIso8601String()]]);
    }

    public function payslip(string $number): JsonResponse
    {
        $p = Payslip::query()->with('employee')->where('number', $number)->firstOrFail();
        app(AuditRecorder::class)->record(AuditAction::PayslipAccessed, 'payroll', $p, reason: 'API read ('.request()->attributes->get('api_key')?->name.')');

        return response()->json(['data' => ['number' => $p->number, 'employee_code' => $p->employee?->employee_code, 'period' => $p->get('period.label'), 'totals' => $p->get('totals'), 'days' => $p->get('days'), 'earnings' => $p->get('earnings'), 'deductions' => $p->get('deductions'), 'employer_contributions' => $p->get('employer_contributions')]]);
    }

    public function payslips(Request $request): JsonResponse
    {
        $query = Payslip::query()->with('employee')
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))
            ->when($request->query('period'), fn (Builder $q, $p) => $q->where('number', 'like', '%-'.str_replace('-', '', $p).'-%'))
            ->orderByDesc('generated_at');

        return $this->page($query, $request, fn (Payslip $p) => ['number' => $p->number, 'employee_code' => $p->employee?->employee_code, 'period' => $p->get('period.label'), 'totals' => $p->get('totals'), 'days' => $p->get('days'), 'earnings' => $p->get('earnings'), 'deductions' => $p->get('deductions')]);
    }

    public function documents(Request $request): JsonResponse
    {
        $query = EmployeeDocument::query()->with(['employee', 'type'])->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))->orderByDesc('id');

        return $this->page($query, $request, fn (EmployeeDocument $d) => ['id' => $d->id, 'employee_code' => $d->employee?->employee_code, 'type' => $d->type?->code, 'title' => $d->title, 'status' => $d->status, 'version' => $d->version, 'issued_on' => $d->issued_on?->toDateString(), 'expires_on' => $d->expires_on?->toDateString()]);
    }

    public function assets(Request $request): JsonResponse
    {
        $query = Asset::query()->with(['category', 'custodian', 'location'])->when($request->query('status'), fn (Builder $q, $s) => $q->where('status', $s))->orderBy('asset_tag');

        return $this->page($query, $request, fn (Asset $a) => ['id' => $a->id, 'asset_tag' => $a->asset_tag, 'name' => $a->name, 'category' => $a->category?->code, 'serial_number' => $a->serial_number, 'status' => $a->status, 'condition' => $a->condition, 'custodian_code' => $a->custodian?->employee_code, 'location' => $a->location?->name, 'warranty_until' => $a->warranty_until?->toDateString()]);
    }

    public function appraisals(Request $request): JsonResponse
    {
        $query = Appraisal::query()->with(['employee', 'cycle'])->when($request->query('cycle'), fn (Builder $q, $c) => $q->whereHas('cycle', fn ($s) => $s->where('code', $c)))->orderByDesc('id');

        return $this->page($query, $request, fn (Appraisal $a) => ['id' => $a->id, 'employee_code' => $a->employee?->employee_code, 'cycle' => $a->cycle?->code, 'status' => $a->status, 'final_rating' => $a->final_rating === null ? null : (float) $a->final_rating, 'final_label' => $a->final_label, 'promotion_recommended' => $a->promotion_recommended]);
    }

    public function goals(Request $request): JsonResponse
    {
        $query = Goal::query()->with('employee')->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))->orderByDesc('id');

        return $this->page($query, $request, fn (Goal $g) => ['id' => $g->id, 'employee_code' => $g->employee?->employee_code, 'level' => $g->level, 'title' => $g->title, 'weight' => $g->weight, 'progress' => (float) $g->progress, 'status' => $g->status, 'due_date' => $g->due_date?->toDateString()]);
    }

    public function workflowInstances(Request $request): JsonResponse
    {
        $query = WorkflowInstance::query()->with('workflow')->when($request->query('status'), fn (Builder $q, $s) => $q->where('status', $s))->orderByDesc('id');

        return $this->page($query, $request, fn (WorkflowInstance $i) => ['id' => $i->id, 'workflow' => $i->workflow?->key, 'status' => $i->status instanceof \BackedEnum ? $i->status->value : $i->status, 'outcome' => $i->outcome, 'subject' => ['type' => $i->subject_type, 'id' => $i->subject_id], 'started_at' => $i->started_at?->toIso8601String(), 'completed_at' => $i->completed_at?->toIso8601String()]);
    }

    public function workflowTasks(Request $request): JsonResponse
    {
        $query = WorkflowTask::query()->with('instance.workflow')->when($request->query('status'), fn (Builder $q, $s) => $q->where('status', $s))->orderByDesc('id');

        return $this->page($query, $request, fn (WorkflowTask $t) => ['id' => $t->id, 'instance_id' => $t->workflow_instance_id, 'workflow' => $t->instance?->workflow?->key, 'title' => $t->title, 'status' => $t->status instanceof \BackedEnum ? $t->status->value : $t->status, 'assignee_id' => $t->assignee_id, 'due_at' => $t->due_at?->toIso8601String()]);
    }

    /**
     * A shared report runs as its owner, as a scheduled run does: the owner's dataset permission and organisation scope
     * decide the rows, and an inactive owner means no run. A starter report has no owner and runs with the key's own
     * reach. Either way the result goes to an integration, not a person, so it is unattended: only reviewed datasets
     * and fields (analytics.unattended_datasets), and the key must also hold the scopes that guard the same data in
     * this API. Every run and every refusal is audited; a refusal returns no rows.
     */
    public function runReport(int $report, Request $request, ReportRunner $runner): JsonResponse
    {
        $report = Report::query()->findOrFail($report);
        $key = $request->attributes->get('api_key');
        $audit = app(AuditRecorder::class);
        $refuse = function (string $code, string $message) use ($report, $key, $audit): JsonResponse {
            $audit->record(AuditAction::Rejected, 'analytics', $report, [], $message, metadata: ['channel' => 'api', 'api_key_id' => $key?->id, 'dataset' => $report->dataset, 'refusal' => $code]);

            return response()->json(['message' => $message], 403);
        };

        if (! $report->is_shared) {
            return $refuse('not_shared', 'Only shared reports can be run through the API.');
        }
        $reviewed = config('peopleos.analytics.unattended_datasets.'.$report->dataset);
        if (! is_array($reviewed)) {
            return $refuse('dataset_not_reviewed', 'This report\'s dataset is not available through the API.');
        }
        $missing = array_values(array_filter((array) ($reviewed['scopes'] ?? []), fn (string $scope) => ! $key->hasScope($scope)));
        if ($missing !== []) {
            return $refuse('scope_missing', 'This key also needs the ['.implode('], [', $missing).'] scope to run this report.');
        }
        $owner = $report->owner_id === null ? null : User::query()->forCurrentTenant()->find($report->owner_id);
        if ($report->owner_id !== null && ($owner === null || ! $owner->isActive())) {
            return $refuse('owner_inactive', 'The report owner is not an active user; the API runs a report with its owner\'s access.');
        }

        $previous = auth()->user();
        $owner ? auth()->setUser($owner) : auth()->forgetUser();
        try {
            $result = $runner->run($report, $owner, max(1, min((int) $request->query('limit', 1000), 5000)), unattended: true);
        } catch (RuntimeException $e) {
            $refusal = $e->getMessage();
        } finally {
            $previous ? auth()->setUser($previous) : auth()->forgetUser();
        }
        if (isset($refusal)) {
            return $refuse('refused', $refusal);
        }

        $audit->record(AuditAction::Export, 'analytics', $report, [], 'API run ('.$key?->name.')', metadata: ['channel' => 'api', 'api_key_id' => $key?->id, 'dataset' => $report->dataset, 'run_as' => $owner?->id, 'rows' => count($result->rows), 'total' => $result->total, 'columns' => array_keys($result->columns)]);

        return response()->json(['data' => ['report' => $report->name, 'dataset' => $report->dataset, 'columns' => $result->columns, 'rows' => $result->rows, 'total' => $result->total, 'grouped' => $result->grouped]]);
    }
}
