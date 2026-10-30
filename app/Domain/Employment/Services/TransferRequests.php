<?php

namespace App\Domain\Employment\Services;

use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Actions\TransferEmployeeAction;
use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\TransferRequest;
use App\Domain\Employment\Models\WorkforceReason;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\SalaryComponent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Transfer requests (HROne setup parity, Workforce → Transfer policy). Someone with `employee.position` asks for a
 * transfer from a date, changing only the dimensions (and manager) the transfer policy allows, within its notice and
 * backdating days, with a reason when required; someone else with `employee.position` approves it, which applies the
 * position change through TransferEmployeeAction, or rejects it. Without a transfer policy any dimension may change.
 * HROne setup parity 08.6: a request may grant transfer allowances (pay components marked as such), which payroll pays
 * once the request is approved, with the payroll of the month the transfer takes effect (TransferAllowanceInput).
 */
final class TransferRequests
{
    public function __construct(private readonly PolicyResolver $policies) {}

    /**
     * @param  array<string, int|string|null>  $changes  position dimension => new id
     * @param  list<array{salary_component_id: int|string, amount: float|int|string}>  $allowances  HROne setup parity 08.6: transfer allowances, paid once by payroll
     */
    public function request(Employee $employee, string $effectiveDate, array $changes, ?int $newManagerId, ?int $reasonId, ?string $note, User $by, array $allowances = []): TransferRequest
    {
        if (! $by->hasPermission('employee.position')) {
            throw new RuntimeException('You cannot request transfers.');
        }
        $changes = array_filter(array_intersect_key($changes, EmployeePosition::DIMENSIONS), fn ($v) => $v !== null && $v !== '');
        if ($changes === [] && $newManagerId === null) {
            throw new RuntimeException('Choose what the transfer changes.');
        }
        $date = Carbon::parse($effectiveDate)->startOfDay();
        $policy = $this->policies->resolve('transfer', $employee, $date);
        $allowed = array_values(array_filter((array) ($policy?->setting('changeable_dimensions') ?? [])));
        if ($allowed !== []) {
            $outside = array_diff(array_keys($changes), $allowed);
            if ($outside !== [] || ($newManagerId !== null && ! in_array('manager', $allowed, true))) {
                $labels = array_map(fn ($d) => EmployeePosition::DIMENSIONS[$d][1] ?? 'Reporting manager', $outside ?: ['manager']);
                throw new RuntimeException('The transfer policy does not allow changing: '.implode(', ', $labels).'.');
            }
        }
        $notice = (int) ($policy?->setting('notice_days') ?? 0);
        $back = (int) ($policy?->setting('backdate_days') ?? 0);
        if ($date->gt(now()->startOfDay()) && $date->lt(now()->startOfDay()->addDays($notice))) {
            throw new RuntimeException("Transfers need {$notice} day(s) notice.");
        }
        if ($date->lt(now()->startOfDay()->subDays($back))) {
            throw new RuntimeException($back > 0 ? "A transfer can be backdated by at most {$back} day(s)." : 'A transfer cannot be backdated.');
        }
        if ($reasonId === null && in_array($policy?->setting('reason_required'), [true, 1, '1'], true)) {
            throw new RuntimeException('Choose a transfer reason.');
        }
        if ($reasonId !== null && ! WorkforceReason::query()->ofKind('transfer')->whereKey($reasonId)->exists()) {
            throw new RuntimeException('Choose a transfer reason from the list.');
        }
        if (TransferRequest::query()->where('employee_id', $employee->id)->where('status', 'pending')->exists()) {
            throw new RuntimeException('A transfer request for this employee is already waiting.');
        }
        $allowances = $this->allowances($allowances);

        $request = new TransferRequest(['employee_id' => $employee->id, 'effective_date' => $date, 'workforce_reason_id' => $reasonId, 'changes' => $changes, 'new_manager_id' => $newManagerId,
            'allowances' => $allowances === [] ? null : $allowances, 'note' => $note, 'requested_by' => $by->id]);
        $request->withAuditReason($note ?: 'Transfer requested')->save();
        EmploymentEvent::dispatch('employee.transfer_requested', $employee, $request, ['effective_date' => $date->toDateString(), 'changes' => $request->summary()]);

        return $request;
    }

    public function approve(TransferRequest $request, ?string $note, User $by): TransferRequest
    {
        return $this->decide($request, $by, 'approved', $note, function (TransferRequest $r) use ($note) {
            app(TransferEmployeeAction::class)->handle($r->employee, $r->changes ?? [], $r->effective_date, $r->new_manager_id, $note ?: ($r->reason?->name ?? 'Approved transfer request #'.$r->id));
        });
    }

    public function reject(TransferRequest $request, string $note, User $by): TransferRequest
    {
        if (trim($note) === '') {
            throw new RuntimeException('Say why the transfer is rejected.');
        }

        return $this->decide($request, $by, 'rejected', $note);
    }

    public function cancel(TransferRequest $request, string $note, User $by): TransferRequest
    {
        if ($request->status !== 'pending') {
            throw new RuntimeException('Only a waiting request can be cancelled.');
        }
        $request->withAuditReason($note)->update(['status' => 'cancelled', 'review_note' => $note, 'reviewed_by' => $by->id, 'reviewed_at' => now()]);

        return $request;
    }

    /**
     * HROne setup parity 08.6: each allowance is an active pay component marked as a transfer allowance (an earning or a
     * reimbursement), once, with a positive amount.
     *
     * @return list<array{salary_component_id: int, amount: float}>
     */
    private function allowances(array $rows): array
    {
        $clean = [];
        foreach ($rows as $row) {
            if (blank($row['salary_component_id'] ?? null) && blank($row['amount'] ?? null)) {
                continue;
            }
            $component = SalaryComponent::query()->where('status', 'active')->where('transfer_allowance', true)->whereIn('type', ['earning', 'reimbursement'])->find($row['salary_component_id'] ?? 0)
                ?? throw new RuntimeException('Choose a pay component marked as a transfer allowance.');
            if (isset($clean[$component->id])) {
                throw new RuntimeException("{$component->name} is listed twice.");
            }
            $amount = round((float) ($row['amount'] ?? 0), 2);
            if (! is_numeric($row['amount'] ?? null) || $amount <= 0) {
                throw new RuntimeException("Give the amount of {$component->name}.");
            }
            $clean[$component->id] = ['salary_component_id' => (int) $component->id, 'amount' => $amount];
        }

        return array_values($clean);
    }

    private function decide(TransferRequest $request, User $by, string $status, ?string $note, ?callable $apply = null): TransferRequest
    {
        if (! $by->hasPermission('employee.position')) {
            throw new RuntimeException('You cannot decide transfers.');
        }

        return DB::transaction(function () use ($request, $by, $status, $note, $apply) {
            $locked = TransferRequest::query()->with(['employee.person', 'reason'])->lockForUpdate()->findOrFail($request->id);
            if ($locked->status !== 'pending') {
                throw new RuntimeException('This request has already been decided.');
            }
            if ((int) $locked->requested_by === (int) $by->id || (int) $locked->employee->user_id === (int) $by->id) {
                throw new RuntimeException('Someone other than the requester and the employee decides a transfer.');
            }
            if ($apply !== null) {
                $apply($locked);
            }
            $locked->withAuditReason($note ?: ucfirst($status))->update(['status' => $status, 'review_note' => $note, 'reviewed_by' => $by->id, 'reviewed_at' => now()]);

            return $locked;
        });
    }
}
