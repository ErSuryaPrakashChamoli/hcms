<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveBalance;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Leave\Services\LeaveYear;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Leave API (Phase 3 §45): tenant bound by the key, employees addressed by code, every change through
 * the Leaves service. Leave reasons are returned only on single-request reads; the calendar never
 * carries them. POST /requests honours an Idempotency-Key header.
 */
class LeaveController extends Controller
{
    use PaginatesApi;

    public function types(Request $request): JsonResponse
    {
        return $this->page(LeaveType::query()->where('status', 'active')->orderBy('sort_order')->orderBy('code'), $request, fn (LeaveType $t) => [
            'code' => $t->code, 'name' => $t->name, 'category' => $t->category, 'unit' => $t->unit, 'is_paid' => (bool) $t->is_paid, 'allow_half_day' => (bool) $t->allow_half_day,
            'requires_document' => (bool) $t->requires_document, 'requires_approval' => (bool) $t->requires_approval, 'cancellation_policy' => $t->cancellation_policy,
        ]);
    }

    public function balances(Request $request, LeaveYear $years): JsonResponse
    {
        $period = (int) ($request->query('period') ?? $years->periodFor(now()));
        $query = LeaveBalance::query()->with(['employee:id,employee_code', 'leaveType:id,code'])->where('period_year', $period)
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))
            ->orderBy('employee_id');

        return $this->page($query, $request, fn (LeaveBalance $b) => ['employee_code' => $b->employee?->employee_code, 'leave_type' => $b->leaveType?->code, 'period' => $b->period_year, 'opening' => (float) $b->opening, 'accrued' => (float) $b->accrued, 'adjusted' => (float) $b->adjusted, 'used' => (float) $b->used, 'pending' => (float) $b->pending, 'encashed' => (float) $b->encashed, 'lapsed' => (float) $b->lapsed, 'closing' => (float) $b->closing, 'available' => $b->available()]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $query = LeaveLedgerEntry::query()->with(['employee:id,employee_code', 'leaveType:id,code'])
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))
            ->when($request->query('period'), fn (Builder $q, $p) => $q->where('period_year', $p))
            ->orderByDesc('entry_date')->orderByDesc('id');

        return $this->page($query, $request, fn (LeaveLedgerEntry $e) => ['id' => $e->id, 'employee_code' => $e->employee?->employee_code, 'leave_type' => $e->leaveType?->code, 'period' => $e->period_year, 'date' => $e->entry_date?->toDateString(), 'type' => $e->type, 'days' => (float) $e->days, 'note' => $e->note]);
    }

    public function requests(Request $request): JsonResponse
    {
        $query = LeaveRequest::query()->with(['employee:id,employee_code', 'leaveType:id,code'])
            ->when($request->query('status'), fn (Builder $q, $s) => $q->where('status', $s))
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))
            ->when($request->query('from'), fn (Builder $q, $d) => $q->whereDate('to_date', '>=', $d))
            ->when($request->query('to'), fn (Builder $q, $d) => $q->whereDate('from_date', '<=', $d))
            ->orderByDesc('from_date');

        return $this->page($query, $request, fn (LeaveRequest $r) => $this->present($r));
    }

    public function show(int $leaveRequest): JsonResponse
    {
        $r = LeaveRequest::query()->with(['employee:id,employee_code', 'leaveType:id,code'])->findOrFail($leaveRequest);

        return response()->json(['data' => $this->present($r) + ['reason' => $r->reason, 'dates' => $r->dates, 'review_note' => $r->review_note, 'cancel_reason' => $r->cancel_reason]]);
    }

    /** GET leave/calendar — who is away when; no reasons. */
    public function calendar(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $query = LeaveRequest::query()->with(['employee:id,employee_code', 'leaveType:id,code'])
            ->whereIn('status', [...LeaveRequest::TAKEN, 'pending'])
            ->whereDate('from_date', '<=', $data['to'])->whereDate('to_date', '>=', $data['from'])
            ->orderBy('from_date');

        return $this->page($query, $request, fn (LeaveRequest $r) => ['employee_code' => $r->employee?->employee_code, 'leave_type' => $r->leaveType?->code, 'from_date' => $r->from_date->toDateString(), 'to_date' => $r->to_date->toDateString(), 'status' => $r->status, 'dates' => collect($r->dates ?? [])->map(fn ($d) => ['date' => $d['date'], 'session' => $d['session'] ?? 'full'])->all()]);
    }

    public function store(Request $request, Leaves $leaves): JsonResponse
    {
        $data = $request->validate([
            'employee_code' => ['required', 'string', 'max:32'],
            'leave_type' => ['required', 'string', 'max:16'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'from_session' => ['nullable', Rule::in(['full', 'first_half', 'second_half'])],
            'to_session' => ['nullable', Rule::in(['full', 'first_half', 'second_half'])],
            'reason' => ['required', 'string', 'max:500'],
            'contact_details' => ['nullable', 'string', 'max:255'],
        ]);
        $key = $request->header('Idempotency-Key');
        if ($key !== null && strlen($key) > 64) {
            return response()->json(['message' => 'Idempotency-Key must be at most 64 characters.'], 422);
        }

        $employee = Employee::query()->where('employee_code', $data['employee_code'])->firstOrFail();
        $type = LeaveType::query()->where('code', $data['leave_type'])->firstOrFail();

        try {
            $leave = $leaves->request($employee, $type, $data['from_date'], $data['to_date'], $data['reason'], $data['from_session'] ?? 'full', $data['to_session'] ?? 'full', null, null, $key, $data['contact_details'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $leave->loadMissing(['employee:id,employee_code', 'leaveType:id,code']);

        return response()->json(['data' => $this->present($leave)], $leave->wasRecentlyCreated ? 201 : 200);
    }

    public function cancel(Request $request, int $leaveRequest, Leaves $leaves): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $leave = LeaveRequest::query()->findOrFail($leaveRequest);

        if ($leave->status === 'cancelled') {
            return response()->json(['data' => $this->present($leave->loadMissing(['employee:id,employee_code', 'leaveType:id,code']))]); // idempotent retry
        }

        try {
            // The API acts for HR (key scope leave.write); the leave type's policy still applies to self-service in the UI.
            $leave = $leaves->cancel($leave, $data['reason']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($leave->loadMissing(['employee:id,employee_code', 'leaveType:id,code']))]);
    }

    /** @return array<string, mixed> */
    private function present(LeaveRequest $r): array
    {
        return ['id' => $r->id, 'employee_code' => $r->employee?->employee_code, 'leave_type' => $r->leaveType?->code, 'from_date' => $r->from_date->toDateString(), 'to_date' => $r->to_date->toDateString(), 'from_session' => $r->from_session, 'to_session' => $r->to_session, 'days' => (float) $r->days, 'status' => $r->status, 'reviewed_at' => $r->reviewed_at?->toIso8601String(), 'cancelled_at' => $r->cancelled_at?->toIso8601String()];
    }
}
