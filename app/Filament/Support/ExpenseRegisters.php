<?php

namespace App\Filament\Support;

use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Filament\Resources\ExpenseReceipts\ExpenseReceiptResource;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * HROne parity XO-1 / XO-2 (matrix 26 §26.1–§26.4): the register depth shared by the expense and travel registers, on top
 * of WO-1 / WO-2 (HasRegisterTools, EmployeeFilters) as TX-1 did for the time office:
 *
 * - filters(): pasted codes, the organisation group, employee status / type / joining, the pending-resignation toggle and
 *   the register's own date ranges — shown only to the register's HR side (`oversees()`), so an employee's own list stays
 *   as it was.
 * - cancellation(): HROne's "Travel cancellations" / "Advance cancellations" registers — cancellations waiting, cancelled,
 *   and cancellations an approver refused (`cancel_refused_at`, kept when the record went back to approved).
 * - waitingWith(): whom a waiting record waits with — the open tasks of the workflow running on it, else the expense
 *   approvers of the Approval Center (the same source the Approval Center lists it from).
 * - receipt checks, type and payment status filters for the expense receipt register (XO-2).
 */
final class ExpenseRegisters
{
    /** HROne's cancellation registers, as one filter on trips and advances. */
    public const CANCELLATION = [
        'requested' => 'Cancellation waiting for a decision',
        'cancelled' => 'Cancelled',
        'refused' => 'Cancellation refused (kept)',
    ];

    /** Receipt checks the "Checks" filter offers (config peopleos.expense.receipt_flags labels them). */
    public const CHECKS = ['over_limit', 'suspected_duplicate', 'same_invoice_number', 'distance_edited'];

    /** The report's payment status, as ExpenseReport::paymentStatus() words it. */
    public const PAYMENT = [
        'paid' => 'Paid',
        'scheduled' => 'Scheduled for payroll',
        'waiting' => 'Waiting for payment',
        'nothing' => 'Nothing to pay (or not in a report)',
    ];

    /**
     * @param  Closure(): bool  $hrSide  the register's HR side (its resource's oversees())
     * @param  array<string, array{0: string, 1: string}>  $dates  filter name => [label, date column of the register]
     * @return list<BaseFilter>
     */
    public static function filters(Closure $hrSide, array $dates = [], string $relation = 'employee'): array
    {
        $filters = [
            EmployeeFilters::codes($relation),
            EmployeeFilters::organisation($relation),
            EmployeeFilters::workforce($relation),
            ...array_map(fn (string $name) => EmployeeFilters::dateRange($name, $dates[$name][0], $dates[$name][1]), array_keys($dates)),
            EmployeeFilters::excludePendingResignation($relation),
        ];

        return array_map(fn (BaseFilter $filter) => $filter->visible(fn () => $hrSide()), $filters);
    }

    /** Cancellations waiting, cancelled, and cancellations refused (HROne's cancellation registers). */
    public static function cancellation(Closure $hrSide): SelectFilter
    {
        return SelectFilter::make('cancellation')
            ->label('Cancellation')
            ->multiple()
            ->options(self::CANCELLATION)
            ->query(function (Builder $query, array $data): Builder {
                $values = array_values(array_intersect((array) ($data['values'] ?? []), array_keys(self::CANCELLATION)));
                if ($values === []) {
                    return $query;
                }
                $model = $query->getModel();

                return $query->where(function (Builder $q) use ($values, $model) {
                    foreach ($values as $value) {
                        match ($value) {
                            'requested' => $q->orWhere($model->qualifyColumn('status'), 'cancel_requested'),
                            'cancelled' => $q->orWhere($model->qualifyColumn('status'), 'cancelled'),
                            'refused' => $q->orWhereNotNull($model->qualifyColumn('cancel_refused_at')),
                        };
                    }
                });
            })
            ->visible(fn () => $hrSide());
    }

    /** Trips overlapping a period (HROne "Travel date"): starting on or before its end and ending on or after its start. */
    public static function travelDates(Closure $hrSide): Filter
    {
        return Filter::make('travel_dates')
            ->label('Travel date')
            ->columns(2)
            ->schema([
                DatePicker::make('from')->label('Travelling from')->native(false),
                DatePicker::make('until')->label('Travelling until')->native(false),
            ])
            ->query(function (Builder $query, array $data): Builder {
                $from = filled($data['from'] ?? null) ? Carbon::parse($data['from'])->toDateString() : null;
                $until = filled($data['until'] ?? null) ? Carbon::parse($data['until'])->toDateString() : null;
                $model = $query->getModel();

                return $query
                    ->when($from !== null, fn (Builder $q) => $q->whereDate($model->qualifyColumn('end_date'), '>=', $from))
                    ->when($until !== null, fn (Builder $q) => $q->whereDate($model->qualifyColumn('start_date'), '<=', $until));
            })
            ->indicateUsing(function (array $data): array {
                $parts = array_filter([
                    filled($data['from'] ?? null) ? 'from '.Carbon::parse($data['from'])->format('j M Y') : null,
                    filled($data['until'] ?? null) ? 'until '.Carbon::parse($data['until'])->format('j M Y') : null,
                ]);

                return $parts === [] ? [] : [Indicator::make('Travelling '.implode(' ', $parts))];
            })
            ->visible(fn () => $hrSide());
    }

    /**
     * Whom a waiting record waits with: the pending tasks of the workflow running on each subject (a mixed trip waits as
     * its parts), else the expense approvers of the Approval Center.
     */
    public static function waitingWith(Model ...$subjects): ?string
    {
        $labels = [];
        $approvalCenter = false;
        foreach ($subjects as $subject) {
            $run = WorkflowInstance::query()->with('workflow')
                ->whereIn('subject_type', array_unique([$subject::class, $subject->getMorphClass()]))->where('subject_id', $subject->getKey())
                ->whereIn('status', InstanceStatus::inFlight())->latest('id')->first();
            if ($run === null) {
                $approvalCenter = true;

                continue;
            }
            $tasks = WorkflowTask::query()->with(['assignee', 'assigneeRole'])->where('workflow_instance_id', $run->id)->where('status', TaskStatus::Pending->value)->orderBy('id')->get();
            foreach ($tasks as $task) {
                $labels[] = $task->assigneeLabel();
            }
            if ($tasks->isEmpty()) {
                $labels[] = 'Workflow: '.($run->workflow?->name ?? 'run #'.$run->id);
            }
        }
        if ($approvalCenter) {
            $labels[] = 'Expense approvers (Approval Center)';
        }

        return $labels === [] ? null : implode(', ', array_values(array_unique($labels)));
    }

    /** "Waiting with" of a trip: its own approval or cancellation, or (a trip of several workflow groups) its pending parts. */
    public static function tripWaitingWith(TravelRequest $trip): ?string
    {
        if ($trip->status === 'cancel_requested') {
            return self::waitingWith($trip);
        }
        if ($trip->status !== 'submitted') {
            return null;
        }
        if ($trip->hasParts()) {
            $parts = $trip->parts()->where('status', 'pending')->get()->all();

            return $parts === [] ? null : self::waitingWith(...$parts);
        }

        return self::waitingWith($trip);
    }

    /** "Waiting with" of an advance: its approval or cancellation; an advance asked for inside a trip is decided with it. */
    public static function advanceWaitingWith(ExpenseAdvance $advance): ?string
    {
        if ($advance->status === 'requested' && $advance->travel_request_id !== null) {
            $trip = TravelRequest::query()->find($advance->travel_request_id);

            return 'Decided with the trip'.($trip !== null ? ' '.$trip->number.(($with = self::tripWaitingWith($trip)) !== null ? ': '.$with : '') : '');
        }

        return in_array($advance->status, ['requested', 'cancel_requested'], true) ? self::waitingWith($advance) : null;
    }

    // --- Expense receipts (XO-2) -------------------------------------------------------------------------------------

    public static function checkLabel(string $flag): string
    {
        return (string) config("peopleos.expense.receipt_flags.{$flag}", $flag);
    }

    /** "Checks": receipts carrying any of the chosen flags (over the limit, suspected duplicate, invoice claimed, distance edited). */
    public static function checks(Closure $hrSide): SelectFilter
    {
        return SelectFilter::make('checks')
            ->label('Checks')
            ->multiple()
            ->options(fn () => collect(self::CHECKS)->mapWithKeys(fn (string $flag) => [$flag => self::checkLabel($flag)])->all())
            ->query(function (Builder $query, array $data): Builder {
                $flags = array_values(array_intersect((array) ($data['values'] ?? []), self::CHECKS));
                if ($flags === []) {
                    return $query;
                }
                $column = $query->getModel()->qualifyColumn('flags');

                return $query->where(function (Builder $q) use ($flags, $column) {
                    foreach ($flags as $flag) {
                        $q->orWhereJsonContains($column, $flag);
                    }
                });
            })
            ->visible(fn () => $hrSide());
    }

    /** "Payment status" of a receipt: its report's, as ExpenseReport::paymentStatus() decides it. */
    public static function paymentStatus(Closure $hrSide): SelectFilter
    {
        $payable = fn (Builder $r) => $r->whereIn('status', ['approved', 'partly_approved'])->where('net_payable', '>', 0)->whereNull('paid_on');

        return SelectFilter::make('payment_status')
            ->label('Payment status')
            ->options(self::PAYMENT)
            ->query(function (Builder $query, array $data) use ($payable): Builder {
                return match ($data['value'] ?? null) {
                    'paid' => $query->whereHas('report', fn (Builder $r) => $r->whereNotNull('paid_on')),
                    'scheduled' => $query->whereHas('report', fn (Builder $r) => $payable($r)->where('payment_channel', 'payroll')),
                    'waiting' => $query->whereHas('report', fn (Builder $r) => $payable($r)->where(fn (Builder $c) => $c->whereNull('payment_channel')->orWhere('payment_channel', '!=', 'payroll'))),
                    'nothing' => $query->where(fn (Builder $q) => $q->whereNull($q->getModel()->qualifyColumn('expense_report_id'))
                        ->orWhereHas('report', fn (Builder $r) => $r->whereNull('paid_on')->where(fn (Builder $n) => $n->whereNotIn('status', ['approved', 'partly_approved'])->orWhere('net_payable', '<=', 0)))),
                    default => $query,
                };
            })
            ->visible(fn () => $hrSide());
    }

    /**
     * The receipts the duplicate check matched (`duplicate_matches`; for receipts captured before XO-2, the one receipt
     * the verdict named), split into those the viewer may open — with a link to their page — and a count of the others
     * (the check compares across the organisation; the viewer sees no more than they already could).
     *
     * @return array{visible: list<array{number: HtmlString|string, employee: ?string, date: ?string, amount: string, why: string}>, hidden: int}
     */
    public static function duplicateMatches(ExpenseReceipt $receipt): array
    {
        $matches = collect((array) ($receipt->duplicate_matches ?? []))->filter(fn ($m) => is_array($m) && isset($m['id']))->keyBy(fn (array $m) => (int) $m['id']);
        if ($matches->isEmpty() && $receipt->duplicate_of_id !== null) {
            $matches = collect([(int) $receipt->duplicate_of_id => ['id' => (int) $receipt->duplicate_of_id, 'score' => $receipt->duplicate_score, 'reasons' => ['similar']]]);
        }
        $user = auth()->user();
        $found = $matches->isEmpty() || $user === null ? collect()
            : ExpenseReceipt::query()->with(['employee.person'])->whereKey($matches->keys()->all())->get()->filter(fn (ExpenseReceipt $m) => $user->can('view', $m))->keyBy('id');
        $visible = [];
        foreach ($matches as $id => $match) {
            $other = $found->get($id);
            if ($other === null) {
                continue;
            }
            $url = rescue(fn () => ExpenseReceiptResource::getUrl('view', ['record' => $other]), null, false);
            $reasons = array_map(fn (string $reason) => $reason === 'invoice' ? 'Same invoice number' : 'Similar amount, date or merchant', (array) ($match['reasons'] ?? []));
            $visible[] = [
                'number' => $url !== null ? new HtmlString('<a class="text-primary-600 underline" href="'.e($url).'">'.e($other->number).'</a>') : $other->number,
                'employee' => $other->employee?->display_name,
                'date' => $other->expense_date?->format('j M Y'),
                'amount' => $other->currency.' '.number_format((float) $other->amount, 2).' · '.(ExpenseReceipt::STATUSES[$other->status] ?? $other->status),
                'why' => implode('; ', $reasons).(isset($match['score']) && $match['score'] !== null ? ' ('.$match['score'].'%)' : ''),
            ];
        }

        return ['visible' => $visible, 'hidden' => $matches->count() - count($visible)];
    }

    /** Whether the duplicate check matched anything for the receipt (no query). */
    public static function hasMatches(ExpenseReceipt $receipt): bool
    {
        return ((array) ($receipt->duplicate_matches ?? [])) !== [] || $receipt->duplicate_of_id !== null;
    }

    /** The matched receipts as lines for the receipt page (one query per render). */
    public static function matchesHtml(ExpenseReceipt $receipt): ?HtmlString
    {
        ['visible' => $visible, 'hidden' => $hidden] = self::duplicateMatches($receipt);
        $lines = array_map(fn (array $row) => ($row['number'] instanceof HtmlString ? $row['number']->toHtml() : e($row['number']))
            .e(' · '.implode(' · ', array_filter([$row['employee'], $row['date'], $row['amount']])).' — '.$row['why']), $visible);
        if ($hidden > 0) {
            $lines[] = e(($visible === [] ? '' : 'and ').$hidden.' receipt(s) you cannot open');
        }

        return $lines === [] ? null : new HtmlString(implode('<br>', $lines));
    }

    /** A receipt's payment status: its report's (blank when it is in none). */
    public static function receiptPayment(ExpenseReceipt $receipt): ?string
    {
        $report = $receipt->report;

        return $report instanceof ExpenseReport ? $report->paymentStatus() : null;
    }

    /**
     * The register's filters as report conditions: the shared WO-1 mapping plus the register's status (as the dataset
     * labels it) and date ranges, through TimeOfficeRegisters::reportFilters (fields a dataset lacks are dropped).
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, array{0: string, 1: (Closure(string): string)|null}>  $selects
     * @param  array<string, string>  $dates
     * @param  array<string, array{0: string, 1: string}>  $endDates  overlap filter name => [dataset start field, dataset end field]
     * @return list<array{field: string, operator: string, value: string|null}>
     */
    public static function reportFilters(array $state, array $selects = [], array $dates = [], array $endDates = []): array
    {
        $out = TimeOfficeRegisters::reportFilters(array_diff_key($state, ['cancellation' => true, 'checks' => true, 'payment_status' => true, 'travel_dates' => true]), $selects, $dates);
        foreach ($endDates as $name => [$startField, $endField]) {
            foreach (['until' => [$startField, 'lte'], 'from' => [$endField, 'gte']] as $bound => [$field, $operator]) {
                if (filled($state[$name][$bound] ?? null)) {
                    $out[] = ['field' => $field, 'operator' => $operator, 'value' => Carbon::parse($state[$name][$bound])->toDateString()];
                }
            }
        }

        return $out;
    }
}
