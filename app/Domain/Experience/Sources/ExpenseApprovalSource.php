<?php

namespace App\Domain\Experience\Sources;

use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseReportPart;
use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Expense\Models\TravelRequestPart;
use App\Domain\Expense\Services\ExpenseAdvances;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Expense\Services\TravelRequests;
use App\Domain\Experience\Contracts\ApprovalSource;
use App\Domain\Experience\Contracts\DescribesApprovalKinds;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Filament\Resources\ExpenseAdvances\ExpenseAdvanceResource;
use App\Filament\Resources\ExpenseReports\ExpenseReportResource;
use App\Filament\Resources\TravelRequests\TravelRequestResource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HROne setup parity EX-C/EX-D decisions in the Approval Center: expense report parts (one per workflow group of a
 * submitted report; the exceptional part apart), expense advances and their cancellations. Records already running a
 * workflow are decided as workflow tasks, so they are not listed twice. Approving a report part here approves every line
 * as claimed; line amounts are changed on the report itself. "Return to employee" sends the whole report back.
 * EX-F: travel requests and their cancellations; an advance asked for inside a travel request is decided with the trip,
 * so it is not listed on its own. Owner decision 2026-10-29: a trip spanning several workflow groups is listed once per
 * part still waiting (only that group's legs); the trip itself is not listed until it is decided.
 */
final class ExpenseApprovalSource implements ApprovalSource, DescribesApprovalKinds
{
    private const SCAN = 200;

    public function types(): array
    {
        return ['expense_report', 'expense_exceptional', 'expense_advance', 'expense_advance_cancellation', 'travel_request', 'travel_request_part', 'travel_cancellation'];
    }

    /** HROne setup parity P3: request names for Setup → Inbox actions (reports keep their own "Return to employee"). */
    public function kindLabels(): array
    {
        return ['expense_report' => 'Expense report', 'expense_exceptional' => 'Exceptional expense', 'expense_advance' => 'Expense advance', 'expense_advance_cancellation' => 'Expense advance cancellation',
            'travel_request' => 'Travel request', 'travel_request_part' => 'Travel request (workflow group)', 'travel_cancellation' => 'Travel cancellation'];
    }

    public function pending(User $user, ?int $about = null): array
    {
        if (! $user->hasPermission('expense.approve')) {
            return [];
        }

        return [...$this->parts($user, $about), ...$this->advances($user, $about), ...$this->trips($user, $about), ...$this->tripParts($user, $about)];
    }

    public function decide(ApprovalItem $item, User $user, string $decision, ?string $note): string
    {
        $record = $item->record;

        return match ($item->type) {
            'expense_report', 'expense_exceptional' => match ($decision) {
                'approve' => tap('Expenses approved', fn () => app(ExpenseReports::class)->approvePart($record, null, $note, $user)),
                'reject' => tap('Expenses rejected', fn () => app(ExpenseReports::class)->rejectPart($record, (string) $note, $user)),
                'request_change' => tap('Report returned to the employee', fn () => app(ExpenseReports::class)->returnReport($record, (string) $note, $user)),
                default => throw new AuthorizationException('Unsupported decision.'),
            },
            'expense_advance' => tap($decision === 'approve' ? 'Advance approved' : 'Advance rejected', fn () => $decision === 'approve'
                ? app(ExpenseAdvances::class)->approve($record, null, $note, $user)
                : app(ExpenseAdvances::class)->reject($record, (string) $note, $user)),
            'expense_advance_cancellation' => tap($decision === 'approve' ? 'Cancellation approved' : 'Advance kept', fn () => $decision === 'approve'
                ? app(ExpenseAdvances::class)->approveCancellation($record, $note, $user)
                : app(ExpenseAdvances::class)->rejectCancellation($record, (string) $note, $user)),
            'travel_request' => tap($decision === 'approve' ? 'Travel approved' : 'Travel rejected', fn () => $decision === 'approve'
                ? app(TravelRequests::class)->approve($record, $note, $user)
                : app(TravelRequests::class)->reject($record, (string) $note, $user)),
            'travel_request_part' => tap($decision === 'approve' ? 'Travel approved for this workflow group' : 'Travel rejected', fn () => $decision === 'approve'
                ? app(TravelRequests::class)->approvePart($record, $note, $user)
                : app(TravelRequests::class)->rejectPart($record, (string) $note, $user)),
            'travel_cancellation' => tap($decision === 'approve' ? 'Cancellation approved' : 'Trip kept', fn () => $decision === 'approve'
                ? app(TravelRequests::class)->approveCancellation($record, $note, $user)
                : app(TravelRequests::class)->rejectCancellation($record, (string) $note, $user)),
            default => throw new AuthorizationException('Unsupported item.'),
        };
    }

    /** @return list<ApprovalItem> */
    private function parts(User $user, ?int $about): array
    {
        $rows = ExpenseReportPart::query()->with(['report', 'employee.person', 'receipts.category'])->where('status', 'pending')
            ->whereHas('report', fn ($q) => $q->where('status', 'submitted'))
            ->when($about !== null, fn ($q) => $q->where('employee_id', $about))->orderBy('id')->limit(self::SCAN)->get();
        $rows = $this->withoutWorkflow($rows, ExpenseReportPart::class);
        app(AccessScopes::class)->primeEmployeeIds($user, $rows->pluck('employee_id'));

        return $rows->filter(fn (ExpenseReportPart $p) => $user->can('approve', $p))->map(function (ExpenseReportPart $p) {
            $report = $p->report;
            $receipts = $p->receipts;
            $flagged = $receipts->filter(fn ($r) => $r->hasFlag('suspected_duplicate') || $r->hasFlag('same_invoice_number'))->count();
            $over = $receipts->filter(fn ($r) => $r->hasFlag('over_limit'))->count();

            return new ApprovalItem(
                id: ($p->isExceptional() ? 'expense_exceptional:' : 'expense_report:').$p->id,
                type: $p->isExceptional() ? 'expense_exceptional' : 'expense_report',
                typeLabel: $p->isExceptional() ? 'Exceptional expense' : 'Expense',
                title: ($report?->number ?? 'Expense report').' · '.$p->label.' · '.number_format((float) $p->total_claimed, 2).' '.($report?->currency ?? ''),
                subject: $p->employee?->display_name,
                subjectEmployeeId: $p->employee_id,
                reason: $p->isExceptional() ? $receipts->pluck('exception_reason')->filter()->unique()->implode('; ') : $report?->title,
                impact: $receipts->count().' expense(s)'.($report?->period_from ? ' from '.$report->period_from->format('d M').' to '.$report->period_to?->format('d M Y') : '').'; approving repays them as claimed (change line amounts on the report)',
                effectiveOn: null,
                dueAt: $report?->submitted_at?->copy()->addDays(3),
                requestedAt: $report?->submitted_at,
                requestedBy: null,
                risk: $flagged > 0 || $p->isExceptional() ? 'high' : 'normal',
                riskReason: $flagged > 0 ? $flagged.' expense(s) look like duplicates' : ($p->isExceptional() ? 'Exceptional expense' : null),
                decisions: ['approve', 'reject', 'request_change'],
                url: $report !== null ? $this->url(fn () => ExpenseReportResource::getUrl('view', ['record' => $report])) : null,
                record: $p,
                changes: $receipts->take(6)->map(fn ($r) => ['label' => $r->number.' · '.($r->category?->name ?? ''), 'before' => null, 'after' => number_format((float) $r->claimable_amount, 2).($r->hasFlag('over_limit') ? ' (capped at the limit)' : '')])->values()->all(),
                facts: array_values(array_filter([$over > 0 ? $over.' over the limit' : null, $flagged > 0 ? $flagged.' suspected duplicate(s)' : null])),
                decisionLabels: ['request_change' => 'Return to employee'],
            );
        })->values()->all();
    }

    /** @return list<ApprovalItem> */
    private function advances(User $user, ?int $about): array
    {
        $rows = ExpenseAdvance::query()->with(['employee.person', 'requester'])->whereIn('status', ['requested', 'cancel_requested'])
            // EX-F: an advance asked for inside a travel request is decided with the trip.
            ->where(fn ($q) => $q->whereNull('travel_request_id')->orWhere('status', 'cancel_requested'))
            ->when($about !== null, fn ($q) => $q->where('employee_id', $about))->orderBy('id')->limit(self::SCAN)->get();
        $rows = $this->withoutWorkflow($rows, ExpenseAdvance::class);
        app(AccessScopes::class)->primeEmployeeIds($user, $rows->pluck('employee_id'));

        return $rows->filter(fn (ExpenseAdvance $a) => $user->can('approve', $a))->map(function (ExpenseAdvance $a) {
            $cancellation = $a->status === 'cancel_requested';

            return new ApprovalItem(
                id: ($cancellation ? 'expense_advance_cancellation:' : 'expense_advance:').$a->id,
                type: $cancellation ? 'expense_advance_cancellation' : 'expense_advance',
                typeLabel: 'Expense advance',
                title: ($cancellation ? 'Cancel advance ' : 'Advance ').$a->number.' · '.number_format($a->baseAmount(), 2),
                subject: $a->employee?->display_name,
                subjectEmployeeId: $a->employee_id,
                reason: $cancellation ? $a->cancel_reason : $a->purpose,
                impact: $cancellation ? 'The approved advance will not be paid' : 'Paid '.($a->disbursement_channel === 'payroll' ? 'with payroll' : 'by bank').' once approved; settled against expense reports',
                effectiveOn: $a->needed_on,
                dueAt: ($cancellation ? $a->cancel_requested_at : $a->created_at)?->copy()->addDays(2),
                requestedAt: $cancellation ? $a->cancel_requested_at : $a->created_at,
                requestedBy: $a->requester?->name,
                risk: 'normal',
                riskReason: null,
                decisions: ['approve', 'reject'],
                url: $this->url(fn () => ExpenseAdvanceResource::getUrl('index')),
                record: $a,
                decisionLabels: $cancellation ? ['approve' => 'Approve cancellation', 'reject' => 'Keep advance'] : [],
            );
        })->values()->all();
    }

    /** @return list<ApprovalItem> EX-F: travel requests and their cancellations no workflow is running for */
    private function trips(User $user, ?int $about): array
    {
        $rows = TravelRequest::query()->with(['employee.person', 'requester', 'legs.category', 'advance'])->whereIn('status', ['submitted', 'cancel_requested'])
            // Owner decision 2026-10-29: a trip of several workflow groups waits as its parts (tripParts), not as a whole.
            ->where(fn ($q) => $q->where('status', 'cancel_requested')->orWhereNull('workflow_group')->orWhere('workflow_group', '!=', TravelRequest::MIXED))
            ->when($about !== null, fn ($q) => $q->where('employee_id', $about))->orderBy('id')->limit(self::SCAN)->get();
        $rows = $this->withoutWorkflow($rows, TravelRequest::class);
        app(AccessScopes::class)->primeEmployeeIds($user, $rows->pluck('employee_id'));

        return $rows->filter(fn (TravelRequest $t) => $user->can('approve', $t))->map(function (TravelRequest $t) {
            $cancellation = $t->status === 'cancel_requested';
            $company = $t->legs->where('booked_by', 'company')->count();

            return new ApprovalItem(
                id: ($cancellation ? 'travel_cancellation:' : 'travel_request:').$t->id,
                type: $cancellation ? 'travel_cancellation' : 'travel_request',
                typeLabel: 'Travel',
                title: ($cancellation ? 'Cancel trip ' : 'Trip ').$t->number.' · '.$t->start_date->format('j M').'–'.$t->end_date->format('j M Y')
                    .(($places = $t->destinations()) !== '' ? ' · '.$places : ''),
                subject: $t->employee?->display_name,
                subjectEmployeeId: $t->employee_id,
                reason: $cancellation ? $t->cancel_reason : $t->purpose,
                impact: $cancellation ? 'The trip, its bookings and any unpaid advance are cancelled'
                    : $t->legs->count().' leg(s)'.($company > 0 ? ", {$company} booked by the company through the travel desk" : '')
                        .($t->advance_amount !== null ? '; advance of '.number_format((float) $t->advance_amount, 2).' '.$t->currency.' decided with it' : ''),
                effectiveOn: $t->start_date,
                dueAt: ($cancellation ? $t->cancel_requested_at : $t->submitted_at)?->copy()->addDays(2),
                requestedAt: $cancellation ? $t->cancel_requested_at : $t->submitted_at,
                requestedBy: $t->requester?->name,
                risk: $t->backdated_reason !== null || $t->is_international ? 'high' : 'normal',
                riskReason: $t->backdated_reason !== null ? 'Backdated: '.$t->backdated_reason : ($t->is_international ? 'International travel' : null),
                decisions: ['approve', 'reject'],
                url: $this->url(fn () => TravelRequestResource::getUrl('view', ['record' => $t])),
                record: $t,
                changes: $t->legs->take(6)->map(fn ($l) => ['label' => $l->summary(), 'before' => null, 'after' => $l->starts_on->format('j M').($l->ends_on ? '–'.$l->ends_on->format('j M') : '').' · '.($l->category?->name ?? '')])->values()->all(),
                facts: array_values(array_filter([$t->estimated_cost_base !== null ? 'Estimated '.number_format((float) $t->estimated_cost_base, 2) : null, $t->is_international ? 'International' : null])),
                decisionLabels: $cancellation ? ['approve' => 'Approve cancellation', 'reject' => 'Keep trip'] : [],
            );
        })->values()->all();
    }

    /**
     * @return list<ApprovalItem> owner decision 2026-10-29: the parts (one per workflow group) of trips spanning several
     *                            groups, each with only its own legs, that no workflow is running for
     */
    private function tripParts(User $user, ?int $about): array
    {
        $rows = TravelRequestPart::query()->with(['employee.person', 'trip.requester', 'trip.parts', 'legs.category'])->where('status', 'pending')
            ->whereHas('trip', fn ($q) => $q->where('status', 'submitted'))
            ->when($about !== null, fn ($q) => $q->where('employee_id', $about))->orderBy('id')->limit(self::SCAN)->get();
        $rows = $this->withoutWorkflow($rows, TravelRequestPart::class);
        app(AccessScopes::class)->primeEmployeeIds($user, $rows->pluck('employee_id'));

        return $rows->filter(fn (TravelRequestPart $p) => $user->can('approve', $p))->map(function (TravelRequestPart $p) {
            $t = $p->trip;
            $company = $p->legs->where('booked_by', 'company')->count();
            $approved = $t->parts->where('status', 'approved')->count();

            return new ApprovalItem(
                id: 'travel_request_part:'.$p->id,
                type: 'travel_request_part',
                typeLabel: 'Travel',
                title: 'Trip '.$t->number.' · '.$p->label.' · '.$t->start_date->format('j M').'–'.$t->end_date->format('j M Y')
                    .(($places = $p->legs->pluck('to_city')->filter()->unique()->implode(', ')) !== '' ? ' · '.$places : ''),
                subject: $p->employee?->display_name,
                subjectEmployeeId: $p->employee_id,
                reason: $t->purpose,
                impact: $p->legs->count().' leg(s) of this workflow group'.($company > 0 ? ", {$company} booked by the company" : '')
                    .'; the trip is approved when every group has approved ('.$approved.' of '.$t->parts->count().' so far), rejecting rejects the whole trip'
                    .($t->advance_amount !== null ? '; its advance of '.number_format((float) $t->advance_amount, 2).' '.$t->currency.' is decided with it' : ''),
                effectiveOn: $t->start_date,
                dueAt: $t->submitted_at?->copy()->addDays(2),
                requestedAt: $t->submitted_at,
                requestedBy: $t->requester?->name,
                risk: $t->backdated_reason !== null || $t->is_international ? 'high' : 'normal',
                riskReason: $t->backdated_reason !== null ? 'Backdated: '.$t->backdated_reason : ($t->is_international ? 'International travel' : null),
                decisions: ['approve', 'reject'],
                url: $this->url(fn () => TravelRequestResource::getUrl('view', ['record' => $t])),
                record: $p,
                changes: $p->legs->take(6)->map(fn ($l) => ['label' => $l->summary(), 'before' => null, 'after' => $l->starts_on->format('j M').($l->ends_on ? '–'.$l->ends_on->format('j M') : '').' · '.($l->category?->name ?? '')])->values()->all(),
                facts: array_values(array_filter([$t->estimated_cost_base !== null ? 'Estimated '.number_format((float) $t->estimated_cost_base, 2).' (whole trip)' : null, $t->is_international ? 'International' : null])),
            );
        })->values()->all();
    }

    /**
     * @template T of Model
     *
     * @param  Collection<int, T>  $rows
     * @return Collection<int, T>
     */
    private function withoutWorkflow(Collection $rows, string $class): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }
        $open = WorkflowInstance::query()->whereIn('subject_type', [$class, (new $class)->getMorphClass()])->whereIn('subject_id', $rows->pluck('id'))
            ->whereIn('status', InstanceStatus::inFlight())->pluck('subject_id')->map(fn ($id) => (int) $id)->all();

        return $rows->reject(fn (Model $r) => in_array((int) $r->getKey(), $open, true))->values();
    }

    private function url(\Closure $url): ?string
    {
        try {
            return $url();
        } catch (Throwable) {
            return null;
        }
    }
}
