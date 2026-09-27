<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Assets\Models\Asset;
use App\Domain\Documents\Models\EmployeeDocument;
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

/** Read API (§87): paginated, tenant-scoped by the API key, filtered with simple query parameters. */
class ReadController extends Controller
{
    public function payrollRuns(Request $request): JsonResponse
    {
        $query = PayrollRun::query()->with(['period', 'company'])->whereIn('status', ['finalized', 'paid'])->orderByDesc('id');

        return $this->page($query, $request, fn (PayrollRun $r) => ['id' => $r->id, 'company' => $r->company?->code, 'period' => $r->period?->start_date?->format('Y-m'), 'status' => $r->status, 'totals' => $r->totals, 'finalized_at' => $r->finalized_at?->toIso8601String()]);
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

    public function runReport(int $report, Request $request, ReportRunner $runner): JsonResponse
    {
        $report = Report::query()->findOrFail($report);
        if (! $report->is_shared) {
            return response()->json(['message' => 'Only shared reports can be run through the API.'], 403);
        }
        $result = $runner->run($report, null, min((int) $request->query('limit', 1000), 5000));

        return response()->json(['data' => ['report' => $report->name, 'dataset' => $report->dataset, 'columns' => $result->columns, 'rows' => $result->rows, 'total' => $result->total, 'grouped' => $result->grouped]]);
    }

    private function page(Builder $query, Request $request, callable $map): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 50), 1), 200);
        $paginator = $query->paginate($perPage)->appends($request->query());

        return response()->json([
            'data' => collect($paginator->items())->map($map)->values()->all(),
            'meta' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ]);
    }
}
