<?php

namespace App\Domain\Expense\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Expense\Events\ExpenseEvent;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Expense\Models\TravelRequestLeg;
use App\Domain\Expense\Models\TravelRequestPart;
use App\Domain\Expense\Policies\TravelRequestPolicy;
use App\Domain\Expense\Support\ExpenseSettings;
use App\Domain\Experience\Services\SelfServiceLock;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Support\Numbering\NumberSeries;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * HROne setup parity EX-F: travel requests (trips), from submission to approval, bookings and cancellation.
 *
 * - Submit (the employee with `expense.claim`, or an approver in scope on their behalf), under a lock on the employee:
 *   purpose and dates; legs in travel-linked categories of the employee's expense policy, each inside the trip (a journey:
 *   mode, from, to, date and optional return; a stay: city, check-in and check-out), one journey and one stay only when
 *   expense.travel.multi_leg is off, and legs of one workflow group only when expense.travel.mixed_categories is off; the
 *   travel form matrix (ExpenseForms: hidden fields are not kept, mandatory ones must be filled; visa and passport only
 *   for international travel); backdating up to expense.travel.backdated_days with the delay reason when asked
 *   (expense.travel.backdated_reason_required; filing on behalf is exempt); overlapping trips refused when
 *   expense.travel.block_overlap is on (a trip may start the day another ends with expense.travel.same_day_turnaround);
 *   an advance inside the trip when expense.travel.advance_allowed (the expense advance policy applies; it is decided
 *   with the trip). The trip raises `expense.travel_requested` with its workflow group (expense.travel.workflow.*), so a
 *   workflow decides it; otherwise it waits in the Approval Center.
 * - Owner decision 2026-10-29: a trip whose legs fall in several workflow groups stays one record (workflow group
 *   `mixed`) with one approval part per group (TravelRequestPart, holding that group's legs). Each part raises
 *   `expense.travel_requested` with its own workflow group and is decided on its own (approvePart / rejectPart, by its
 *   workflow or in the Approval Center); the trip is approved when every part is and rejected — its other parts
 *   cancelled — when any part is. Such a trip is never decided as a whole.
 * - Approve / reject (in scope, never one's own): the trip's advance with it; on approval the company's bookings are
 *   raised for the travel desk (TravelBookings) and the trip is `booking` until they are recorded (`booked`).
 * - Cancel: a trip waiting for approval at once; an approved one at once by an approver, otherwise after approval
 *   (`expense.travel_cancel_requested`); never once expenses are claimed against it. Cancelling cancels its legs and its
 *   advance (unless already paid) and tells the travel desk.
 */
final class TravelRequests
{
    public function __construct(
        private readonly ExpensePolicies $policies,
        private readonly ExpenseForms $forms,
        private readonly ExpenseSettings $settings,
        private readonly ExpenseAdvances $advances,
        private readonly TravelBookings $bookings,
        private readonly CurrencyRates $rates,
        private readonly NumberSeries $numbers,
        private readonly AuditRecorder $audit,
        private readonly WorkflowEngine $workflows,
    ) {}

    /** Where travel attachments are kept (the tenant's expense folder on the private documents disk). */
    public static function directory(): string
    {
        return 'tenants/'.app(TenantContext::class)->id().'/expense/travel';
    }

    /**
     * @param  array<string, mixed>  $data  purpose, start_date, end_date, currency, estimated_cost, is_international, visa_number, passport_number,
     *                                      attachments, backdated_reason, advance_amount, custom [key => value], legs [{expense_category_id, mode,
     *                                      from_city, from_state, to_city, to_state, starts_on, ends_on, preferred_time, booked_by, notes}]
     */
    public function submit(Employee $employee, array $data, ?User $actor = null): TravelRequest
    {
        $actor = $this->authorise($actor, $employee);
        $onBehalf = (int) $employee->user_id !== (int) $actor->id;
        // HROne setup parity P4: the employee request lock refuses the employee's own trip while it applies.
        app(SelfServiceLock::class)->assertOpen($employee, 'travel', $actor, ExpenseRuleViolation::class);
        $checked = $this->evaluate($employee, $data, $onBehalf);
        $this->numbers->prepare('travel_request', NumberSeries::companyOf($employee));

        return DB::transaction(function () use ($employee, $actor, $checked, $onBehalf) {
            $this->lockEmployee($employee);
            $this->assertNoOverlap($employee, $checked['start'], $checked['end']);
            $trip = new TravelRequest($checked['attributes'] + [
                'number' => $this->numbers->next('travel_request', NumberSeries::companyOf($employee)), 'employee_id' => $employee->id,
                'status' => 'submitted', 'requested_by' => $actor->id, 'submitted_at' => now(),
            ]);
            $trip->withAuditReason($onBehalf ? 'Submitted on behalf of the employee' : null)->save();
            // Owner decision 2026-10-29: legs of several workflow groups are approved part by part (one part per group).
            $parts = [];
            if (count($checked['groups']) > 1) {
                foreach ($checked['groups'] as $group) {
                    $first = $checked['legs'][array_search($group, $checked['leg_groups'], true)];
                    $parts[$group] = TravelRequestPart::query()->create(['travel_request_id' => $trip->id, 'employee_id' => $employee->id, 'workflow_group' => $group, 'status' => 'pending',
                        'label' => mb_substr(app(ExpenseReports::class)->groupLabel($group, ExpenseCategory::query()->find($first['expense_category_id'])), 0, 255)]);
                }
            }
            foreach ($checked['legs'] as $i => $leg) {
                TravelRequestLeg::query()->create($leg + ['travel_request_id' => $trip->id, 'travel_request_part_id' => ($parts[$checked['leg_groups'][$i]] ?? null)?->id,
                    'employee_id' => $employee->id, 'sequence' => $i + 1]);
            }
            if ($checked['custom'] !== []) {
                try {
                    $trip->setCustomFields($checked['custom']);
                } catch (ValidationException $e) {
                    throw new ExpenseRuleViolation(collect($e->errors())->flatten()->first() ?? 'An additional field is not valid.');
                }
            }
            if ($checked['advance'] !== null) {
                $advance = $this->advances->request($employee, ['amount' => $checked['advance'], 'currency' => $trip->currency, 'purpose' => mb_substr('Travel '.$trip->number.': '.$trip->purpose, 0, 500),
                    'needed_on' => $trip->start_date->toDateString(), 'travel_request_id' => $trip->id], $actor);
                $trip->withoutAuditing()->update(['expense_advance_id' => $advance->id]);
            }
            $this->audit->record(AuditAction::Submitted, 'expense', $trip, reason: $onBehalf ? 'Submitted on behalf of the employee' : null, actor: $actor,
                metadata: ['legs' => count($checked['legs']), 'workflow_group' => $trip->workflow_group] + ($parts !== [] ? ['parts' => count($parts)] : []) + ($onBehalf ? ['on_behalf' => true] : []));
            if ($parts === []) {
                ExpenseEvent::dispatch('expense.travel_requested', $employee, $trip, $this->context($trip->refresh()) + ['workflow_groups' => $checked['groups']], $this->managerUsers($employee));
            }
            foreach ($parts as $part) {
                ExpenseEvent::dispatch('expense.travel_requested', $employee, $part, $this->context($trip->refresh(), $part)
                    + ['workflow_groups' => $checked['groups'], 'travel_request_id' => $trip->id, 'part' => $part->label], $this->managerUsers($employee));
            }

            return $trip;
        });
    }

    public function approve(TravelRequest $trip, ?string $note = null, ?User $actor = null): TravelRequest
    {
        return DB::transaction(function () use ($trip, $note, $actor) {
            [$locked, $actor] = $this->decidable($trip, $actor, ['submitted'], 'approve');
            $this->assertWhole($locked);

            return $this->approveTrip($locked, $note, $actor);
        });
    }

    public function reject(TravelRequest $trip, string $note, ?User $actor = null): TravelRequest
    {
        self::assertNote($note, 'rejecting');

        return DB::transaction(function () use ($trip, $note, $actor) {
            [$locked, $actor] = $this->decidable($trip, $actor, ['submitted'], 'approve');
            $this->assertWhole($locked);

            return $this->rejectTrip($locked, $note, $actor);
        });
    }

    /**
     * Owner decision 2026-10-29: approves one workflow group's part of a trip spanning several groups (in scope, never
     * one's own). Once every part is approved the trip is approved: its advance with it, the company's bookings raised.
     */
    public function approvePart(TravelRequestPart $part, ?string $note = null, ?User $actor = null): TravelRequestPart
    {
        return DB::transaction(function () use ($part, $note, $actor) {
            [$trip, $locked, $actor] = $this->decidablePart($part, $actor);
            $locked->withAuditReason($note)->update(['status' => 'approved', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => self::short($note)]);
            $this->audit->record(AuditAction::Approved, 'expense', $locked, reason: $note, actor: $actor, metadata: ['travel_request' => $trip->number, 'workflow_group' => $locked->workflow_group]);
            if (! TravelRequestPart::query()->withoutGlobalScope(AccessScope::class)->where('travel_request_id', $trip->id)->where('status', '!=', 'approved')->exists()) {
                $this->approveTrip($trip, $note ?? 'Every workflow group approved', $actor);
            }

            return $locked->refresh();
        });
    }

    /** Owner decision 2026-10-29: a rejection by any workflow group rejects the trip; its other open parts are cancelled. */
    public function rejectPart(TravelRequestPart $part, string $note, ?User $actor = null): TravelRequestPart
    {
        self::assertNote($note, 'rejecting');

        return DB::transaction(function () use ($part, $note, $actor) {
            [$trip, $locked, $actor] = $this->decidablePart($part, $actor);
            $locked->withAuditReason($note)->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => self::short($note)]);
            $this->audit->record(AuditAction::Rejected, 'expense', $locked, reason: $note, actor: $actor, metadata: ['travel_request' => $trip->number, 'workflow_group' => $locked->workflow_group]);
            $this->closeParts($trip, 'Rejected by '.$locked->label);
            $this->rejectTrip($trip, $note, $actor);

            return $locked->refresh();
        });
    }

    private function approveTrip(TravelRequest $locked, ?string $note, User $actor): TravelRequest
    {
        $locked->withAuditReason($note)->update(['status' => 'approved', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => self::short($note)]);
        $this->audit->record(AuditAction::Approved, 'expense', $locked, reason: $note, actor: $actor);
        $advance = $this->advance($locked);
        if ($advance !== null && $advance->status === 'requested') {
            $this->advances->approve($advance, null, $note ?? 'Approved with travel request '.$locked->number, $actor);
        }
        $raised = $this->bookings->open($locked, $actor);
        $locked->refresh();
        if ($raised > 0) {
            $locked->withAuditReason('Company bookings requested')->update(['status' => 'booking']);
        }
        $this->tell('expense.travel_approved', $locked, ['bookings' => $raised]);

        return $locked->refresh();
    }

    private function rejectTrip(TravelRequest $locked, string $note, User $actor): TravelRequest
    {
        $locked->withAuditReason($note)->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => self::short($note)]);
        $this->audit->record(AuditAction::Rejected, 'expense', $locked, reason: $note, actor: $actor);
        $advance = $this->advance($locked);
        if ($advance !== null && $advance->status === 'requested') {
            $this->advances->reject($advance, 'Travel request '.$locked->number.' rejected: '.$note, $actor);
        }
        $this->tell('expense.travel_rejected', $locked, ['note' => $note]);

        return $locked;
    }

    /** Owner decision 2026-10-29: a trip of several workflow groups is decided part by part, never as a whole. */
    private function assertWhole(TravelRequest $trip): void
    {
        if ($trip->hasParts()) {
            throw new ExpenseRuleViolation("{$trip->number} is approved by each of its workflow groups: decide its parts (Approvals on the trip, or the Approval Center).");
        }
    }

    /**
     * A trip waiting for approval is cancelled at once. An approved trip is cancelled at once by an approver; the employee's
     * cancellation waits for approval. A trip with expenses claimed against it cannot be cancelled.
     */
    public function cancel(TravelRequest $trip, string $reason, ?User $actor = null): TravelRequest
    {
        self::assertNote($reason, 'cancelling');
        $actor ??= auth()->user();
        $policy = app(TravelRequestPolicy::class);
        if ($actor === null || ! ($policy->update($actor, $trip) || $policy->approve($actor, $trip))) {
            throw new ExpenseRuleViolation('You cannot cancel this travel request.');
        }

        return DB::transaction(function () use ($trip, $reason, $actor, $policy) {
            $this->lockEmployee(Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($trip->employee_id));
            $locked = $this->lock($trip);
            $this->assertNoExpenses($locked);
            if ($locked->status === 'submitted' || ($locked->isApproved() && $policy->approve($actor, $locked))) {
                return $this->close($locked, $reason, $actor);
            }
            if (! $locked->isApproved()) {
                throw new ExpenseRuleViolation('This travel request is '.strtolower(TravelRequest::STATUSES[$locked->status] ?? $locked->status).' and can no longer be cancelled.');
            }
            $locked->withAuditReason($reason)->update(['status' => 'cancel_requested', 'cancel_reason' => self::short($reason), 'cancel_requested_at' => now()]);
            $this->audit->record(AuditAction::Submitted, 'expense', $locked, reason: $reason, actor: $actor, metadata: ['cancellation' => true]);
            $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($locked->employee_id);
            ExpenseEvent::dispatch('expense.travel_cancel_requested', $employee, $locked, $this->context($locked) + ['reason' => $reason], $this->managerUsers($employee));

            return $locked;
        });
    }

    public function approveCancellation(TravelRequest $trip, ?string $note = null, ?User $actor = null): TravelRequest
    {
        return DB::transaction(function () use ($trip, $note, $actor) {
            [$locked, $actor] = $this->decidable($trip, $actor, ['cancel_requested'], 'approve');
            $this->assertNoExpenses($locked);
            $this->audit->record(AuditAction::Approved, 'expense', $locked, reason: $note, actor: $actor, metadata: ['cancellation' => true]);

            return $this->close($locked, (string) ($locked->cancel_reason ?: $note ?: 'Cancellation approved'), $actor, $note);
        });
    }

    public function rejectCancellation(TravelRequest $trip, string $note, ?User $actor = null): TravelRequest
    {
        self::assertNote($note, 'keeping the trip');

        return DB::transaction(function () use ($trip, $note, $actor) {
            [$locked, $actor] = $this->decidable($trip, $actor, ['cancel_requested'], 'approve');
            // HROne parity XO-1: the refused cancellation is recorded (Travel cancellations keeps listing the trip), never removed.
            $locked->withAuditReason($note)->update(['status' => $this->approvedStatus($locked), 'cancel_requested_at' => null, 'cancel_refused_at' => now(), 'decision_note' => self::short($note)]);
            $this->audit->record(AuditAction::Rejected, 'expense', $locked, reason: $note, actor: $actor, metadata: ['cancellation' => true]);

            return $locked;
        });
    }

    /**
     * The employee's approved trips an expense of this category can point at (covering the date for a travel plan
     * category), for the expense form.
     *
     * @return array<int, string>
     */
    public function choicesFor(Employee $employee, ?ExpenseCategory $category, ?string $date): array
    {
        if ($category === null || ($category->travel_link ?: 'none') === 'none') {
            return [];
        }

        return TravelRequest::query()->withoutGlobalScope(AccessScope::class)->with('legs')->where('employee_id', $employee->id)->whereIn('status', TravelRequest::APPROVED)
            ->when($category->travel_link === 'plan' && filled($date), fn ($q) => $q->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date))
            ->orderByDesc('start_date')->limit(50)->get()->mapWithKeys(fn (TravelRequest $t) => [$t->id => $t->displayLabel()])->all();
    }

    /**
     * Every check of a new trip, without saving.
     *
     * @return array{attributes: array<string, mixed>, legs: list<array<string, mixed>>, leg_groups: list<string>, custom: array<string, mixed>, advance: ?float, start: CarbonImmutable, end: CarbonImmutable, groups: list<string>}
     */
    public function evaluate(Employee $employee, array $data, bool $onBehalf = false): array
    {
        $text = fn (string $key, int $max) => filled($data[$key] ?? null) ? mb_substr(trim((string) $data[$key]), 0, $max) : null;
        $purpose = $text('purpose', 500) ?? throw new ExpenseRuleViolation('Give the purpose of the trip.');
        if (blank($data['start_date'] ?? null) || blank($data['end_date'] ?? null)) {
            throw new ExpenseRuleViolation('Give the dates the trip starts and ends.');
        }
        $start = CarbonImmutable::parse($data['start_date'])->startOfDay();
        $end = CarbonImmutable::parse($data['end_date'])->startOfDay();
        if ($end->lt($start)) {
            throw new ExpenseRuleViolation('The trip cannot end before it starts.');
        }
        $today = CarbonImmutable::today();

        // Backdated trips: up to expense.travel.backdated_days, with the delay reason when asked (filing on behalf is exempt).
        $backdatedReason = null;
        if ($start->lt($today) && ! $onBehalf) {
            $allowed = max(0, (int) $this->settings->get('expense.travel.backdated_days', 0));
            $days = (int) $start->diffInDays($today);
            if ($days > $allowed) {
                throw new ExpenseRuleViolation($allowed === 0 ? 'A travel request cannot start in the past.' : "A travel request can be backdated by at most {$allowed} day(s); this one starts {$days} day(s) ago.");
            }
            $backdatedReason = $text('backdated_reason', 500);
            if ($backdatedReason === null && (bool) $this->settings->get('expense.travel.backdated_reason_required', true)) {
                throw new ExpenseRuleViolation('Give the reason for the delay: the trip has already started.');
            }
        } elseif ($start->lt($today)) {
            $backdatedReason = $text('backdated_reason', 500);
        }

        // Legs.
        $legs = [];
        $groups = [];
        foreach (array_values(array_filter((array) ($data['legs'] ?? []), 'is_array')) as $i => $leg) {
            [$legs[], $groups[]] = $this->leg($employee, $leg, $start, $end, $i + 1);
        }
        if ($legs === []) {
            throw new ExpenseRuleViolation('Add at least one journey or stay to the trip.');
        }
        $legGroups = $groups;
        $kinds = array_count_values(array_column($legs, 'kind'));
        if (! (bool) $this->settings->get('expense.travel.multi_leg', true) && max($kinds) > 1) {
            throw new ExpenseRuleViolation('A travel request holds one journey (with its return) and one stay: raise another request for more.');
        }
        $groups = array_values(array_unique($groups));
        if (count($groups) > 1 && ! (bool) $this->settings->get('expense.travel.mixed_categories', true)) {
            throw new ExpenseRuleViolation('The legs of a travel request are approved the same way: put the categories with their own approval in another request.');
        }

        // The travel form: hidden fields are not kept; mandatory ones must be filled (visa and passport for international travel).
        $fields = $this->forms->travelFields();
        $shown = fn (string $key) => (bool) ($fields[$key]['visible'] ?? false);
        $base = $this->rates->baseCurrency();
        $international = $shown('international_travel') && filter_var($data['is_international'] ?? false, FILTER_VALIDATE_BOOL);
        $values = [
            'currency' => $shown('currency') ? (strtoupper(trim((string) ($data['currency'] ?? ''))) ?: null) : null,
            'estimated_cost' => $shown('estimated_cost') && is_numeric($data['estimated_cost'] ?? null) ? round((float) $data['estimated_cost'], 2) : null,
            'international_travel' => $international,
            'visa_number' => $shown('visa_number') && $international ? $text('visa_number', 64) : null,
            'passport_number' => $shown('passport_number') && $international ? $text('passport_number', 64) : null,
            'attachment' => $shown('attachment') ? $this->files((array) ($data['attachments'] ?? [])) : [],
        ];
        $custom = [];
        foreach ((array) ($data['custom'] ?? []) as $key => $value) {
            if ($shown(ExpenseForms::CUSTOM.$key)) {
                $values[ExpenseForms::CUSTOM.$key] = $value;
                if (! blank($value)) {
                    $custom[$key] = $value;
                }
            }
        }
        $missing = array_values(array_filter($this->forms->travelMissing($values), fn (string $label) => $international
            || ! in_array($label, [$fields['visa_number']['label'] ?? null, $fields['passport_number']['label'] ?? null], true)));
        if ($missing !== []) {
            throw new ExpenseRuleViolation('Fill in '.implode(', ', $missing).'.');
        }
        $currency = $values['currency'] ?? $base;
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new ExpenseRuleViolation('Give the currency as a three-letter code, e.g. INR or USD.');
        }
        $estimated = $values['estimated_cost'];
        if ($estimated !== null && $estimated < 0) {
            throw new ExpenseRuleViolation('The estimated cost cannot be negative.');
        }
        $advance = is_numeric($data['advance_amount'] ?? null) && (float) $data['advance_amount'] > 0 ? round((float) $data['advance_amount'], 2) : null;
        if ($advance !== null && ! (bool) $this->settings->get('expense.travel.advance_allowed', true)) {
            throw new ExpenseRuleViolation('Advances are not asked for inside travel requests: request it under Expense advances.');
        }
        $fx = 1.0;
        if ($currency !== $base && ($estimated !== null || $advance !== null)) {
            try {
                $fx = $this->rates->rate($currency, $base, $today);
            } catch (RuntimeException) {
                throw new ExpenseRuleViolation("There is no exchange rate from {$currency} to {$base} today. Ask HR to add it under Exchange rates.");
            }
        }

        return [
            'attributes' => [
                'purpose' => $purpose, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'is_international' => $international,
                'currency' => $currency, 'estimated_cost' => $estimated, 'exchange_rate' => round($fx, 8), 'estimated_cost_base' => $estimated !== null ? round($estimated * $fx, 2) : null,
                'visa_number' => $values['visa_number'], 'passport_number' => $values['passport_number'], 'attachments' => $values['attachment'] ?: null,
                'backdated_reason' => $backdatedReason, 'advance_amount' => $advance, 'workflow_group' => count($groups) === 1 ? $groups[0] : TravelRequest::MIXED,
            ],
            'legs' => $legs,
            'leg_groups' => $legGroups,
            'custom' => $custom,
            'advance' => $advance,
            'start' => $start,
            'end' => $end,
            'groups' => $groups,
        ];
    }

    /**
     * One leg, checked: its category (travel-linked, in the employee's expense policy, active), its places and its dates
     * inside the trip, and who books it.
     *
     * @return array{0: array<string, mixed>, 1: string} the leg's attributes and its workflow group
     */
    private function leg(Employee $employee, array $leg, CarbonImmutable $start, CarbonImmutable $end, int $n): array
    {
        $category = ExpenseCategory::query()->with('group')->find($leg['expense_category_id'] ?? null) ?? throw new ExpenseRuleViolation("Leg {$n}: choose the travel category.");
        if (($category->travel_link ?: 'none') === 'none' || $category->travel_form === null) {
            throw new ExpenseRuleViolation("Leg {$n}: {$category->name} is not a travel category.");
        }
        if (! $category->isActive()) {
            throw new ExpenseRuleViolation("Leg {$n}: {$category->name} is no longer in use.");
        }
        $version = $this->policies->expense($employee, $start);
        if ($version === null || $this->policies->categoryRow($version, $category) === null) {
            throw new ExpenseRuleViolation("Leg {$n}: {$category->name} is not in your expense policy.");
        }
        $text = fn (string $key, int $max) => filled($leg[$key] ?? null) ? mb_substr(trim((string) $leg[$key]), 0, $max) : null;
        $kind = (string) $category->travel_form;
        if (blank($leg['starts_on'] ?? null)) {
            throw new ExpenseRuleViolation($kind === 'accommodation' ? "Leg {$n}: give the check-in date." : "Leg {$n}: give the travel date.");
        }
        $from = CarbonImmutable::parse($leg['starts_on'])->startOfDay();
        $to = filled($leg['ends_on'] ?? null) ? CarbonImmutable::parse($leg['ends_on'])->startOfDay() : null;
        if ($kind === 'accommodation' && $to === null) {
            throw new ExpenseRuleViolation("Leg {$n}: give the check-out date.");
        }
        if ($from->lt($start) || $from->gt($end) || ($to !== null && ($to->lt($from) || $to->gt($end)))) {
            throw new ExpenseRuleViolation("Leg {$n}: its dates must fall within the trip ({$start->format('j M Y')} to {$end->format('j M Y')}), ending on or after they start.");
        }
        $mode = null;
        if ($kind === 'travel') {
            $mode = (string) ($leg['mode'] ?? '');
            if (! array_key_exists($mode, (array) config('peopleos.expense.travel_modes', []))) {
                throw new ExpenseRuleViolation("Leg {$n}: choose how you travel (".implode(', ', (array) config('peopleos.expense.travel_modes')).').');
            }
            if ($text('from_city', 120) === null || $text('to_city', 120) === null) {
                throw new ExpenseRuleViolation("Leg {$n}: give the places you travel from and to.");
            }
        } elseif ($text('to_city', 120) === null) {
            throw new ExpenseRuleViolation("Leg {$n}: give the city of the stay.");
        }
        $time = $text('preferred_time', 5);
        if ($time !== null && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new ExpenseRuleViolation("Leg {$n}: give the preferred time as HH:MM.");
        }
        $bookedBy = match ($category->booked_by) {
            'company' => 'company',
            'either' => in_array($leg['booked_by'] ?? null, ['employee', 'company'], true) ? $leg['booked_by'] : 'employee',
            default => 'employee',
        };

        return [[
            'kind' => $kind, 'expense_category_id' => $category->id, 'mode' => $mode,
            'from_city' => $kind === 'travel' ? $text('from_city', 120) : null, 'from_state' => $kind === 'travel' ? $text('from_state', 64) : null,
            'to_city' => $text('to_city', 120), 'to_state' => $text('to_state', 64),
            'starts_on' => $from->toDateString(), 'ends_on' => $to?->toDateString(), 'preferred_time' => $time,
            'booked_by' => $bookedBy, 'status' => 'pending', 'notes' => $text('notes', 500),
        ], $this->settings->travelWorkflowGroup($category)];
    }

    /** Refuses a trip whose dates overlap another open trip of the employee (expense.travel.block_overlap); under the employee lock. */
    private function assertNoOverlap(Employee $employee, CarbonImmutable $start, CarbonImmutable $end): void
    {
        if (! (bool) $this->settings->get('expense.travel.block_overlap', true)) {
            return;
        }
        $turnaround = (bool) $this->settings->get('expense.travel.same_day_turnaround', true);
        $clash = TravelRequest::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->whereIn('status', TravelRequest::OPEN)
            ->whereDate('start_date', '<=', $end->toDateString())->whereDate('end_date', '>=', $start->toDateString())
            ->lockForUpdate()->get()
            ->first(fn (TravelRequest $t) => ! ($turnaround && ($t->end_date->toDateString() === $start->toDateString() || $t->start_date->toDateString() === $end->toDateString())));
        if ($clash !== null) {
            throw new ExpenseRuleViolation("The dates overlap travel request {$clash->number} ({$clash->start_date->format('j M Y')} to {$clash->end_date->format('j M Y')}).");
        }
    }

    /** A trip with expenses claimed against it (not withdrawn, rejected or expired) stays. */
    private function assertNoExpenses(TravelRequest $trip): void
    {
        $count = ExpenseReceipt::query()->withoutGlobalScope(AccessScope::class)->where('travel_request_id', $trip->id)->whereIn('status', ExpenseReceipt::COUNTING)->count();
        if ($count > 0) {
            throw new ExpenseRuleViolation("{$count} expense(s) are claimed against {$trip->number}: it can no longer be cancelled.");
        }
    }

    /** Cancels the trip: its legs, its running workflows, its advance (unless paid); the travel desk hears of it. */
    private function close(TravelRequest $trip, string $reason, User $actor, ?string $note = null): TravelRequest
    {
        $tickets = TravelRequestLeg::query()->withoutGlobalScope(AccessScope::class)->where('travel_request_id', $trip->id)->whereNotNull('booking_ticket_id')->pluck('booking_ticket_id')->unique()->values()->all();
        TravelRequestLeg::query()->withoutGlobalScope(AccessScope::class)->where('travel_request_id', $trip->id)->get()
            ->each(fn (TravelRequestLeg $leg) => $leg->status === 'cancelled' ? null : $leg->withAuditReason($reason)->update(['status' => 'cancelled']));
        $trip->withAuditReason($reason)->update(['status' => 'cancelled', 'cancel_reason' => self::short($trip->cancel_reason ?: $reason), 'cancelled_at' => now(), 'decision_note' => $note !== null ? self::short($note) : $trip->decision_note]);
        WorkflowInstance::query()->where('subject_type', $trip->getMorphClass())->where('subject_id', $trip->id)->whereIn('status', InstanceStatus::inFlight())->get()
            ->each(fn (WorkflowInstance $instance) => $this->workflows->cancel($instance, 'Travel request cancelled'));
        $this->closeParts($trip, 'Travel request cancelled');
        if (($advance = $this->advance($trip)) !== null) {
            $this->advances->cancelWithTrip($advance, 'Travel request '.$trip->number.' cancelled: '.$reason);
        }
        $this->audit->record(AuditAction::Cancelled, 'expense', $trip, reason: $reason, actor: $actor);
        $this->tell('expense.travel_cancelled', $trip, ['reason' => $reason, 'booking_tickets' => count($tickets)], $this->bookings->deskUsers($tickets));

        return $trip;
    }

    /** Owner decision 2026-10-29: the trip's parts still waiting are cancelled, with their running workflows. */
    private function closeParts(TravelRequest $trip, string $reason): void
    {
        TravelRequestPart::query()->withoutGlobalScope(AccessScope::class)->where('travel_request_id', $trip->id)->where('status', 'pending')->get()
            ->each(function (TravelRequestPart $part) use ($reason): void {
                $part->withAuditReason($reason)->update(['status' => 'cancelled']);
                WorkflowInstance::query()->where('subject_type', $part->getMorphClass())->where('subject_id', $part->id)->whereIn('status', InstanceStatus::inFlight())->get()
                    ->each(fn (WorkflowInstance $instance) => $this->workflows->cancel($instance, $reason));
            });
    }

    /** The status an approved trip goes back to: booking while company bookings are open, booked once recorded. */
    public function approvedStatus(TravelRequest $trip): string
    {
        $legs = TravelRequestLeg::query()->withoutGlobalScope(AccessScope::class)->where('travel_request_id', $trip->id)->where('booked_by', 'company')->pluck('status');

        return match (true) {
            $legs->contains('booking') => 'booking',
            $legs->isNotEmpty() && $legs->every(fn (string $s) => $s === 'booked') => 'booked',
            default => 'approved',
        };
    }

    private function advance(TravelRequest $trip): ?ExpenseAdvance
    {
        return $trip->expense_advance_id !== null ? ExpenseAdvance::query()->withoutGlobalScope(AccessScope::class)->find($trip->expense_advance_id) : null;
    }

    /**
     * @param  list<string>  $statuses
     * @return array{0: TravelRequest, 1: User}
     */
    private function decidable(TravelRequest $trip, ?User $actor, array $statuses, string $ability): array
    {
        $actor ??= auth()->user();
        if ($actor === null) {
            throw new ExpenseRuleViolation('A travel request is decided by a person.');
        }
        $this->lockEmployee(Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($trip->employee_id));
        $locked = $this->lock($trip);
        if (! in_array($locked->status, $statuses, true)) {
            throw new ExpenseRuleViolation('This travel request is '.strtolower(TravelRequest::STATUSES[$locked->status] ?? $locked->status).' and cannot be changed that way now.');
        }
        if (! app(TravelRequestPolicy::class)->{$ability}($actor, $locked)) {
            $own = (int) Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($locked->employee_id)->value('user_id') === (int) $actor->id;

            throw new ExpenseRuleViolation($own ? 'You cannot decide your own travel request.' : 'You cannot decide this travel request.');
        }

        return [$locked, $actor];
    }

    /**
     * Owner decision 2026-10-29: a part waiting for its decision on a trip waiting for approval, and an actor allowed to
     * decide it (in scope, never one's own); under the employee, trip and part locks.
     *
     * @return array{0: TravelRequest, 1: TravelRequestPart, 2: User}
     */
    private function decidablePart(TravelRequestPart $part, ?User $actor): array
    {
        $actor ??= auth()->user();
        if ($actor === null) {
            throw new ExpenseRuleViolation('A travel request is decided by a person.');
        }
        $this->lockEmployee(Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($part->employee_id));
        $trip = TravelRequest::query()->withoutGlobalScope(AccessScope::class)->whereKey($part->travel_request_id)->lockForUpdate()->firstOrFail();
        $locked = TravelRequestPart::query()->withoutGlobalScope(AccessScope::class)->whereKey($part->getKey())->lockForUpdate()->firstOrFail();
        if (! $locked->isPending() || $trip->status !== 'submitted') {
            throw new ExpenseRuleViolation('This part of the travel request has already been decided.');
        }
        if (! app(TravelRequestPolicy::class)->approve($actor, $locked)) {
            $own = (int) Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($locked->employee_id)->value('user_id') === (int) $actor->id;

            throw new ExpenseRuleViolation($own ? 'You cannot decide your own travel request.' : 'You cannot decide this travel request.');
        }

        return [$trip, $locked, $actor];
    }

    private function lock(TravelRequest $trip): TravelRequest
    {
        return TravelRequest::query()->withoutGlobalScope(AccessScope::class)->whereKey($trip->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockEmployee(Employee $employee): void
    {
        Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->getKey())->lockForUpdate()->value('id');
    }

    private function authorise(?User $actor, Employee $employee): User
    {
        $actor ??= auth()->user();
        if ($actor === null) {
            throw new ExpenseRuleViolation('Travel is requested by a person.');
        }
        $own = $employee->user_id !== null && (int) $employee->user_id === (int) $actor->id;
        if ($own ? ! $actor->hasPermission('expense.claim') : ! app(TravelRequestPolicy::class)->onBehalf($actor, $employee)) {
            throw new ExpenseRuleViolation('You cannot request travel for this employee.');
        }

        return $actor;
    }

    /** @return list<string> */
    private function files(array $paths): array
    {
        $prefix = 'tenants/'.app(TenantContext::class)->id().'/expense/';
        $disk = Storage::disk(config('peopleos.documents.disk', 'local'));
        $out = [];
        foreach (array_filter($paths, 'is_string') as $path) {
            if (! str_starts_with($path, $prefix) || str_contains($path, '..') || ! $disk->exists($path)) {
                throw new ExpenseRuleViolation('Attach the documents again: one of them could not be found.');
            }
            $out[] = $path;
        }

        return $out;
    }

    /** @return array<string, mixed> references, dates and amounts only (of the part's legs and group for a part's approval) */
    private function context(TravelRequest $trip, ?TravelRequestPart $part = null): array
    {
        $legs = TravelRequestLeg::query()->withoutGlobalScope(AccessScope::class)->where('travel_request_id', $trip->id)
            ->when($part !== null, fn ($q) => $q->where('travel_request_part_id', $part->id))->orderBy('sequence')->get();

        return [
            'number' => $trip->number, 'workflow_group' => $part?->workflow_group ?? $trip->workflow_group ?? ExpenseSettings::ALL, 'start_date' => $trip->start_date->toDateString(), 'end_date' => $trip->end_date->toDateString(),
            'international' => (bool) $trip->is_international, 'estimated_cost' => $trip->estimated_cost_base !== null ? (float) $trip->estimated_cost_base : null,
            'advance' => $trip->advance_amount !== null ? (float) $trip->advance_amount : null, 'legs' => $legs->count(),
            'destinations' => $legs->pluck('to_city')->filter()->unique()->implode(', '),
        ];
    }

    /** @param  list<int>  $extra */
    private function tell(string $event, TravelRequest $trip, array $context, array $extra = []): void
    {
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($trip->employee_id);
        ExpenseEvent::dispatch($event, $employee, $trip, $this->context($trip) + $context, array_values(array_unique(array_filter([$employee->user_id, ...$extra]))));
    }

    /** @return list<int> the employee's manager's sign-in, for the in-app note when no rule is set up */
    private function managerUsers(Employee $employee): array
    {
        return array_values(array_filter([$employee->currentManager()->with('manager')->first()?->manager?->user_id]));
    }

    private static function assertNote(string $note, string $what): void
    {
        if (trim($note) === '') {
            throw new ExpenseRuleViolation("Give the reason for {$what}.");
        }
    }

    /** Notes are kept to their column (500 characters); the audit trail keeps the full text. */
    private static function short(?string $note): ?string
    {
        return $note === null ? null : mb_substr(trim($note), 0, 500);
    }
}
