<?php

namespace App\Domain\Expense\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Models\ExpenseTaxType;
use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Expense\Policies\ExpensePolicy;
use App\Domain\Expense\Support\ExpenseAttendance;
use App\Domain\Expense\Support\ExpenseSettings;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Numbering\NumberSeries;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * HROne setup parity EX-C: captures, changes and withdraws expense receipts, checking every rule an expense category and
 * policy carry (server side, whatever the screen sent), under a lock on the employee so two claims cannot both use the
 * last of a limit:
 *
 * - the category must be in the employee's expense policy on the expense date (ExpenseLimits); no future dates; before
 *   the joining date only where the category allows it; inside the category's filing window (From–To days after the
 *   expense date); not expired (expense.expiry.*);
 * - the expense form matrix (ExpenseForms: fields mandatory for the category's section), description, from / to places,
 *   the declaration, a receipt above the category's threshold, two odometer photos;
 * - amount categories: the receipt currency is converted at the rate of the expense date (refused when none exists, never
 *   guessed); mileage: distance × rate per km, the distance from the odometer (or a map, when a provider supplies one)
 *   editable only as expense.mileage.* allows;
 * - attendance (present, minimum time, on duty), hourly bands of a daily allowance (worked minutes, auto-filled from
 *   attendance where the category says so), the claim frequency;
 * - limits: the category's or policy's limit for the place's location group and the period's usage, and the policy's
 *   overall limit — over them the claim is capped at what is left, or filed as an exceptional expense with a reason where
 *   the category allows it (exceptional-only categories always are);
 * - taxes (tax heads and the supplier's GSTIN, as printed) and duplicates (ExpenseDuplicateDetector);
 * - EX-F travel links: a "travel plan" category needs one of the employee's approved trips covering the expense date, a
 *   "travel reference" one may point at an approved trip; per-trip limits and frequencies count that trip's expenses.
 */
final class ExpenseReceipts
{
    public function __construct(
        private readonly ExpenseLimits $limits,
        private readonly ExpenseForms $forms,
        private readonly ExpenseLocationGroups $places,
        private readonly ExpenseDuplicateDetector $duplicates,
        private readonly ExpenseAttendance $attendance,
        private readonly ExpenseSettings $settings,
        private readonly CurrencyRates $rates,
        private readonly NumberSeries $numbers,
        private readonly AuditRecorder $audit,
    ) {}

    /** Where receipt files are kept (the tenant's expense folder on the private documents disk). */
    public static function directory(): string
    {
        return 'tenants/'.app(TenantContext::class)->id().'/expense/receipts';
    }

    /**
     * @param  array<string, mixed>  $data  expense_category_id, expense_date, currency, amount, distance_km, computed_distance_km +
     *                                      distance_source (map), odometer_start, odometer_end, from_place, to_place, worked_minutes,
     *                                      location_city, location_state, purpose, merchant, invoice_number, participants, supplier_gstin,
     *                                      description, attachments, taxes [{expense_tax_type_id, rate, amount}], exceptional_reason,
     *                                      declaration_accepted, custom [key => value]
     */
    public function capture(Employee $employee, array $data, ?User $actor = null): ExpenseReceipt
    {
        $actor = $this->authorise($actor, $employee);
        $this->numbers->prepare('expense_receipt', NumberSeries::companyOf($employee));

        return DB::transaction(function () use ($employee, $data, $actor) {
            $this->lockEmployee($employee);
            $checked = $this->evaluate($employee, $data, null);
            $receipt = new ExpenseReceipt($checked['attributes'] + [
                'number' => $this->numbers->next('expense_receipt', NumberSeries::companyOf($employee)),
                'employee_id' => $employee->id, 'status' => 'unreported', 'created_by' => $actor->id,
            ]);
            $receipt->withAuditReason($checked['attributes']['exception_reason'] ?? null)->save();
            $this->saveDetails($receipt, $checked);
            if ((int) $employee->user_id !== (int) $actor->id) {
                $this->audit->record(AuditAction::Submitted, 'expense', $receipt, reason: 'Filed on behalf of the employee', actor: $actor, metadata: ['on_behalf' => true]);
            }

            return $receipt->refresh();
        });
    }

    /** Changes an expense not yet decided: unreported, or in a draft or returned report. Every rule is checked again. */
    public function update(ExpenseReceipt $receipt, array $data, ?User $actor = null): ExpenseReceipt
    {
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($receipt->employee_id);
        $this->authorise($actor, $employee);

        return DB::transaction(function () use ($receipt, $employee, $data) {
            $this->lockEmployee($employee);
            $locked = ExpenseReceipt::query()->withoutGlobalScope(AccessScope::class)->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();
            $this->assertChangeable($locked);
            $checked = $this->evaluate($employee, $data, $locked);
            $locked->fill($checked['attributes']);
            $locked->withAuditReason('Expense changed')->save();
            $locked->taxes()->get()->each(fn ($tax) => $tax->withAuditReason('Expense changed')->delete());
            $this->saveDetails($locked, $checked);
            if ($locked->expense_report_id !== null) {
                // The draft (or returned) report it sits in claims the changed amount.
                ExpenseReport::query()->withoutGlobalScope(AccessScope::class)->find($locked->expense_report_id)?->withoutAuditing()->update(['total_claimed' => round((float) ExpenseReceipt::query()
                    ->withoutGlobalScope(AccessScope::class)->where('expense_report_id', $locked->expense_report_id)->sum('claimable_amount'), 2)]);
            }

            return $locked->refresh();
        });
    }

    /** The employee takes back an expense that is not in a report (remove it from a draft report first). */
    public function withdraw(ExpenseReceipt $receipt, string $reason, ?User $actor = null): ExpenseReceipt
    {
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($receipt->employee_id);
        $this->authorise($actor, $employee);
        if (trim($reason) === '') {
            throw new ExpenseRuleViolation('Give the reason for withdrawing the expense.');
        }

        return DB::transaction(function () use ($receipt, $reason) {
            $locked = ExpenseReceipt::query()->withoutGlobalScope(AccessScope::class)->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'unreported') {
                throw new ExpenseRuleViolation('Only an expense that is not in a report can be withdrawn (remove it from its draft report first).');
            }
            $locked->withAuditReason($reason)->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);

            return $locked;
        });
    }

    /**
     * The scheduled expiry (peopleos:expense:expire): unreported expenses past the expiry period (expense.expiry.*, worked
     * out with today's settings) become expired. Returns how many expired.
     */
    public function expireDue(CarbonInterface $today): int
    {
        if ((int) $this->settings->get('expense.expiry.period', 0) <= 0) {
            return 0;
        }
        $expired = 0;
        ExpenseReceipt::query()->withoutGlobalScope(AccessScope::class)->where('status', 'unreported')->orderBy('id')
            ->chunkById(200, function ($receipts) use ($today, &$expired) {
                foreach ($receipts as $receipt) {
                    $on = $this->settings->expiresOn($receipt->expense_date);
                    if ($on !== null && $on->lt($today->copy()->startOfDay())) {
                        $receipt->withAuditReason('Expense expiry period passed on '.$on->toDateString())->update(['status' => 'expired', 'expired_at' => now(), 'expires_on' => $on->toDateString()]);
                        $expired++;
                    }
                }
            });

        return $expired;
    }

    /** Whether an expense has expired by today's settings (an unreported one cannot then be reported). */
    public function hasExpired(ExpenseReceipt $receipt, ?CarbonInterface $today = null): bool
    {
        $on = $this->settings->expiresOn($receipt->expense_date);

        return $receipt->status === 'expired' || ($on !== null && $on->lt(CarbonImmutable::parse($today ?? today())->startOfDay()));
    }

    /**
     * Every check, without saving: the receipt's attributes, tax lines and custom values, or ExpenseRuleViolation.
     *
     * @return array{attributes: array<string, mixed>, taxes: list<array<string, mixed>>, custom: array<string, mixed>}
     */
    public function evaluate(Employee $employee, array $data, ?ExpenseReceipt $existing): array
    {
        $category = ExpenseCategory::query()->with('group')->find($data['expense_category_id'] ?? null) ?? throw new ExpenseRuleViolation('Choose the expense category.');
        if (blank($data['expense_date'] ?? null)) {
            throw new ExpenseRuleViolation('Give the expense date.');
        }
        $date = CarbonImmutable::parse($data['expense_date'])->startOfDay();
        $today = CarbonImmutable::today();
        $on = $date->format('j M Y');
        if ($date->gt($today)) {
            throw new ExpenseRuleViolation('An expense cannot be dated in the future.');
        }
        if ($employee->joining_date !== null && $date->lt(CarbonImmutable::parse($employee->joining_date)->startOfDay()) && ! $category->before_joining_allowed) {
            throw new ExpenseRuleViolation("{$category->name} cannot be claimed for a date before your date of joining (".CarbonImmutable::parse($employee->joining_date)->format('j M Y').').');
        }
        $filedOn = CarbonImmutable::parse($existing?->created_at ?? $today)->startOfDay();
        $window = $category->submission_window_days;
        if (is_array($window) && isset($window['from'], $window['to'])) {
            $age = (int) round($date->diffInDays($filedOn));
            if ($age < (int) $window['from'] || $age > (int) $window['to']) {
                throw new ExpenseRuleViolation("{$category->name} can be filed between {$window['from']} and {$window['to']} days after the expense date; an expense of {$on} is {$age} day(s) old.");
            }
        }
        $expires = $this->settings->expiresOn($date);
        if ($expires !== null && $expires->lt($today)) {
            throw new ExpenseRuleViolation("Expenses of {$on} expired on {$expires->format('j M Y')} and can no longer be claimed.");
        }
        $trip = $this->trip($employee, $category, $data['travel_request_id'] ?? null, $date);

        $mileage = $category->isMileage();
        $type = $mileage ? ExpenseCategory::MILEAGE : ExpenseCategory::AMOUNT;
        $section = $category->travel_form ?: $type;
        $text = fn (string $key, int $max) => filled($data[$key] ?? null) ? mb_substr(trim((string) $data[$key]), 0, $max) : null;
        $flags = [];

        // The expense form: only fields shown for the category's section are kept; mandatory ones must be filled.
        $participants = array_values(array_filter(array_map(fn ($p) => trim((string) $p), (array) ($data['participants'] ?? [])), fn ($p) => $p !== ''));
        $values = ['purpose' => $text('purpose', 500), 'location' => $text('location_city', 120) ?? $text('location_state', 64), 'participants' => $participants ?: null,
            'merchant' => $text('merchant', 160), 'invoice_number' => ExpenseDuplicateDetector::invoiceKey($data['invoice_number'] ?? null)];
        $custom = [];
        foreach ((array) ($data['custom'] ?? []) as $key => $value) {
            $values[ExpenseForms::CUSTOM.$key] = $value;
        }
        $fields = $this->forms->expenseFields();
        foreach ($values as $key => $value) {
            if (! in_array($section, $fields[$key]['visible'] ?? [], true)) {
                $values[$key] = null;
            } elseif (str_starts_with($key, ExpenseForms::CUSTOM) && ! blank($value)) {
                $custom[substr($key, strlen(ExpenseForms::CUSTOM))] = $value;
            }
        }
        if (($missing = $this->forms->missing($section, $values)) !== []) {
            throw new ExpenseRuleViolation('Fill in '.implode(', ', $missing).'.');
        }
        $locationCity = $values['location'] !== null ? $text('location_city', 120) : null;
        $locationState = $values['location'] !== null ? $text('location_state', 64) : null;
        $group = $locationCity !== null || $locationState !== null ? $this->places->resolve($locationCity, $locationState) : null;

        $description = $text('description', 5000);
        if ($category->description_required && $description === null) {
            throw new ExpenseRuleViolation("Describe the expense: {$category->name} needs a description.");
        }
        if ($category->declaration_required && ! (bool) ($data['declaration_accepted'] ?? false)) {
            throw new ExpenseRuleViolation('Accept the declaration: '.$category->declaration_text);
        }
        $fromPlace = $text('from_place', 160);
        $toPlace = $text('to_place', 160);

        // Attachments: files in the tenant's expense folder, fingerprinted for the duplicate check.
        $attachments = array_values(array_filter(array_map(fn ($p) => is_string($p) ? $p : null, (array) ($data['attachments'] ?? []))));
        $hashes = $this->hashes($attachments);

        // Amount or distance.
        $base = $this->rates->baseCurrency();
        $beyondTolerance = false;
        [$distance, $computed, $source, $start, $end] = [null, null, null, null, null];
        if ($mileage) {
            $source = 'manual';
            if ($category->odometer_required) {
                [$start, $end] = [$data['odometer_start'] ?? null, $data['odometer_end'] ?? null];
                if (! is_numeric($start) || ! is_numeric($end) || (float) $end <= (float) $start) {
                    throw new ExpenseRuleViolation('Give the odometer reading at the start and at the end (the end above the start).');
                }
                if (count($attachments) < 2) {
                    throw new ExpenseRuleViolation('Attach the two odometer photos (start and end).');
                }
                [$start, $end, $computed, $source] = [round((float) $start, 1), round((float) $end, 1), round((float) $end - (float) $start, 2), 'odometer'];
            } elseif (($data['distance_source'] ?? null) === 'map' && is_numeric($data['computed_distance_km'] ?? null)) {
                [$computed, $source] = [round((float) $data['computed_distance_km'], 2), 'map'];
            }
            $distance = is_numeric($data['distance_km'] ?? null) ? round((float) $data['distance_km'], 2) : $computed;
            if ($distance === null || $distance <= 0) {
                throw new ExpenseRuleViolation('Give the distance travelled in km.');
            }
            if ($computed !== null && abs($distance - $computed) > 0.004) {
                if (! (bool) $this->settings->get('expense.mileage.distance_editable', true)) {
                    throw new ExpenseRuleViolation("The distance comes from the {$source} ({$computed} km) and cannot be changed.");
                }
                if ($source === 'map') {
                    if (! (bool) $this->settings->get('expense.mileage.map_edit_allowed', false)) {
                        throw new ExpenseRuleViolation("The distance calculated on the map ({$computed} km) cannot be changed.");
                    }
                    $tolerance = max(0.0, (float) $this->settings->get('expense.mileage.map_edit_tolerance_percent', 10));
                    if ($distance > $computed * (1 + $tolerance / 100) + 0.004) {
                        if (! (bool) $this->settings->get('expense.exceptional.enabled', false)) {
                            throw new ExpenseRuleViolation("The distance is more than {$tolerance}% above the map's {$computed} km.");
                        }
                        $beyondTolerance = true;
                    }
                }
                $flags[] = 'distance_edited';
            }
            if (in_array($category->location_input, ['text', 'map'], true) && ($fromPlace === null || $toPlace === null)) {
                throw new ExpenseRuleViolation('Give the places you travelled from and to.');
            }
            [$currency, $amount, $fx] = [$base, round($distance * (float) $category->rate_per_km, 2), 1.0];
        } else {
            $currency = strtoupper(trim((string) ($data['currency'] ?? ''))) ?: $base;
            if (! preg_match('/^[A-Z]{3}$/', $currency)) {
                throw new ExpenseRuleViolation('Give the currency as a three-letter code, e.g. INR or USD.');
            }
            $amount = is_numeric($data['amount'] ?? null) ? round((float) $data['amount'], 2) : 0.0;
            if ($amount <= 0) {
                throw new ExpenseRuleViolation('The amount must be more than 0.');
            }
            try {
                $fx = $this->rates->rate($currency, $base, $date);
            } catch (RuntimeException) {
                throw new ExpenseRuleViolation("There is no exchange rate from {$currency} to {$base} on or before {$on}. Ask HR to add it under Exchange rates.");
            }
            if ($category->from_location_required && $fromPlace === null) {
                throw new ExpenseRuleViolation('Give the place you started from.');
            }
        }
        $baseAmount = round($amount * $fx, 2);

        // Hours behind a daily allowance, and attendance.
        $minutes = is_numeric($data['worked_minutes'] ?? null) ? max(0, min(1440, (int) $data['worked_minutes'])) : null;
        if ($category->auto_fill_worked_hours && ($worked = $this->attendance->workedMinutes($employee, $date)) !== null) {
            $minutes = $worked;
        }
        if ($category->hourly_constraints_enabled && $minutes === null) {
            throw new ExpenseRuleViolation("Give the hours worked on {$on}: {$category->name} depends on them.");
        }
        if (($refusal = $this->attendance->refusal($employee, $category, $date)) !== null) {
            throw new ExpenseRuleViolation($refusal);
        }

        // Policy, limits and frequency.
        $limit = $this->limits->for($employee, $category, $date, $group, ['minutes' => $minutes, 'except_receipt_id' => $existing?->id,
            'trip' => $trip !== null ? [$trip->start_date, $trip->end_date] : null, 'trip_id' => $trip?->id]);
        if (! $limit->allowed) {
            throw new ExpenseRuleViolation((string) $limit->refusal);
        }
        if ($limit->frequency !== null && $limit->frequency['remaining'] < 1) {
            $period = strtolower((string) config('peopleos.expense.periods.'.$limit->frequency['period'], $limit->frequency['period']));
            throw new ExpenseRuleViolation("{$category->name} can be claimed {$limit->frequency['count']} time(s) {$period}, and that many are already claimed".($limit->frequency['window'] ? " between {$limit->frequency['window'][0]} and {$limit->frequency['window'][1]}" : '').'.');
        }
        $caps = [];
        if ($limit->remaining !== null) {
            $caps[] = $mileage ? round($limit->remaining * (float) $category->rate_per_km, 2) : $limit->remaining;
        }
        if ($limit->overall !== null) {
            $caps[] = (float) $limit->overall['remaining'];
        }
        $cap = $caps === [] ? null : round(min($caps), 2);
        $reason = $text('exceptional_reason', 500);
        $exceptional = false;
        $claimable = $baseAmount;
        $over = 0.0;
        if ($limit->exceptionalOnly) {
            if ($reason === null) {
                throw new ExpenseRuleViolation("{$category->name} is claimed only as an exceptional expense: give the reason.");
            }
            $exceptional = true;
        }
        if ($cap !== null && $baseAmount > $cap + 0.004) {
            $flags[] = 'over_limit';
            $over = round($baseAmount - $cap, 2);
            if ($limit->overLimit === 'exceptional' && $reason !== null) {
                $exceptional = true;
            } elseif (! $exceptional) {
                $claimable = $cap;
            }
        }
        if ($beyondTolerance) {
            if ($reason === null) {
                throw new ExpenseRuleViolation('The distance is more than the map allows: give a reason to file it as an exceptional expense.');
            }
            $exceptional = true;
        }
        if ($limit->needsReceipt($baseAmount) && $attachments === []) {
            throw new ExpenseRuleViolation("Attach the receipt: {$category->name} needs one".($limit->receiptRequiredAbove !== null ? ' above '.number_format($limit->receiptRequiredAbove, 2) : '').'.');
        }

        // Taxes as printed on the receipt.
        $taxes = [];
        $gstin = null;
        if ($category->tax_applicable) {
            foreach ((array) ($data['taxes'] ?? []) as $line) {
                if (! is_array($line) || (blank($line['expense_tax_type_id'] ?? null) && blank($line['amount'] ?? null))) {
                    continue;
                }
                $taxType = ExpenseTaxType::query()->whereKey($line['expense_tax_type_id'] ?? null)->where('status', ActiveStatus::Active)->first()
                    ?? throw new ExpenseRuleViolation('Choose an active tax type for every tax line.');
                if (! is_numeric($line['amount'] ?? null) || (float) $line['amount'] < 0) {
                    throw new ExpenseRuleViolation("Give the {$taxType->name} amount (0 or more).");
                }
                $rate = $line['rate'] ?? null;
                if (filled($rate) && (! is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100)) {
                    throw new ExpenseRuleViolation("The {$taxType->name} rate must be between 0 and 100.");
                }
                $taxes[] = ['expense_tax_type_id' => $taxType->id, 'rate' => filled($rate) ? round((float) $rate, 2) : null, 'amount' => round((float) $line['amount'], 2)];
            }
            if (array_sum(array_column($taxes, 'amount')) > $amount + 0.004) {
                throw new ExpenseRuleViolation('The taxes cannot add up to more than the receipt amount.');
            }
            $gstin = filled($data['supplier_gstin'] ?? null) ? strtoupper(trim((string) $data['supplier_gstin'])) : null;
            if ($gstin !== null && ! preg_match('/^[0-9A-Z]{15}$/', $gstin)) {
                throw new ExpenseRuleViolation('A GST registration number has 15 letters and digits.');
            }
        }

        $duplicate = $this->duplicates->assess(['expense_date' => $date->toDateString(), 'base_amount' => $baseAmount, 'merchant' => $values['merchant'], 'invoice_number' => $values['invoice_number'], 'attachment_hashes' => $hashes], $existing?->id);
        if ($duplicate['refusal'] !== null) {
            throw new ExpenseRuleViolation($duplicate['refusal']);
        }

        return [
            'attributes' => [
                'expense_category_id' => $category->id, 'policy_version_id' => $limit->policyVersionId, 'expense_date' => $date->toDateString(), 'type' => $type,
                'travel_request_id' => $trip?->id,
                'currency' => $currency, 'amount' => $amount, 'exchange_rate' => round($fx, 8), 'base_amount' => $baseAmount,
                'distance_km' => $distance, 'computed_distance_km' => $computed, 'distance_source' => $source, 'odometer_start' => $start, 'odometer_end' => $end,
                'from_place' => $fromPlace, 'to_place' => $toPlace, 'worked_minutes' => $minutes,
                'location_city' => $locationCity, 'location_state' => $locationState, 'expense_location_group_id' => $group?->id,
                'purpose' => $values['purpose'], 'merchant' => $values['merchant'], 'invoice_number' => $values['invoice_number'], 'participants' => $values['participants'],
                'supplier_gstin' => $gstin, 'gst_supply_type' => $category->tax_applicable ? $category->gst_supply_type : null, 'description' => $description,
                'attachments' => $attachments ?: null, 'attachment_hashes' => $hashes ?: null,
                'duplicate_score' => $duplicate['score'], 'duplicate_of_id' => $duplicate['of'], 'duplicate_matches' => $duplicate['matches'] ?: null, 'flags' => array_values(array_unique([...$flags, ...$duplicate['flags']])) ?: null,
                'is_exceptional' => $exceptional, 'exception_reason' => $exceptional ? $reason : null,
                'limit_amount' => $cap ?? $limit->amountLimit, 'over_limit_amount' => $over, 'claimable_amount' => $claimable, 'reimbursable' => (bool) $category->reimbursable,
                'declaration_accepted_at' => $category->declaration_required ? ($existing?->declaration_accepted_at ?? now()) : null,
                'expires_on' => $expires?->toDateString(),
            ],
            'taxes' => $taxes,
            'custom' => $custom,
        ];
    }

    /**
     * EX-F: the trip a travel-linked expense belongs to — required for a travel plan category (an approved trip of the
     * employee covering the expense date), optional for a travel reference one (an approved trip of the employee).
     */
    private function trip(Employee $employee, ExpenseCategory $category, mixed $id, CarbonImmutable $date): ?TravelRequest
    {
        $link = (string) ($category->travel_link ?: 'none');
        if ($link === 'none') {
            return null;
        }
        if (blank($id) || ! is_numeric($id)) {
            if ($link === 'plan') {
                throw new ExpenseRuleViolation("{$category->name} is claimed against an approved travel request: choose the trip.");
            }

            return null;
        }
        $trip = TravelRequest::query()->withoutGlobalScope(AccessScope::class)->whereKey((int) $id)->where('employee_id', $employee->id)->first();
        if ($trip === null || ! $trip->isApproved()) {
            throw new ExpenseRuleViolation('Choose one of your approved travel requests.');
        }
        if ($link === 'plan' && ! $trip->covers($date)) {
            throw new ExpenseRuleViolation("The expense of {$date->format('j M Y')} is outside {$trip->number} ({$trip->start_date->format('j M Y')} to {$trip->end_date->format('j M Y')}).");
        }

        return $trip;
    }

    private function saveDetails(ExpenseReceipt $receipt, array $checked): void
    {
        foreach ($checked['taxes'] as $tax) {
            $receipt->taxes()->create($tax);
        }
        if ($checked['custom'] !== []) {
            try {
                $receipt->setCustomFields($checked['custom']);
            } catch (ValidationException $e) {
                throw new ExpenseRuleViolation(collect($e->errors())->flatten()->first() ?? 'An additional field is not valid.');
            }
        }
    }

    private function assertChangeable(ExpenseReceipt $receipt): void
    {
        $editable = $receipt->status === 'unreported'
            || ($receipt->status === 'reported' && $receipt->expense_report_id !== null && in_array($receipt->report()->withoutGlobalScope(AccessScope::class)->value('status'), ['draft', 'returned'], true));
        if (! $editable) {
            throw new ExpenseRuleViolation('This expense can no longer be changed: it is '.strtolower(ExpenseReceipt::STATUSES[$receipt->status] ?? $receipt->status).'.');
        }
    }

    /** @return list<string> sha256 of each file, which must be in the tenant's expense folder */
    private function hashes(array $paths): array
    {
        $disk = Storage::disk(config('peopleos.documents.disk', 'local'));
        $prefix = 'tenants/'.app(TenantContext::class)->id().'/expense/';
        $hashes = [];
        foreach ($paths as $path) {
            if (! str_starts_with($path, $prefix) || str_contains($path, '..') || ! $disk->exists($path)) {
                throw new ExpenseRuleViolation('Attach the receipt files again: one of them could not be found.');
            }
            $hashes[] = hash('sha256', (string) $disk->get($path));
        }

        return $hashes;
    }

    private function authorise(?User $actor, Employee $employee): User
    {
        $actor ??= auth()->user();
        if ($actor === null) {
            throw new ExpenseRuleViolation('Expenses are filed by a person.');
        }
        $own = $employee->user_id !== null && (int) $employee->user_id === (int) $actor->id;
        if ($own ? ! $actor->hasPermission('expense.claim') : ! app(ExpensePolicy::class)->onBehalf($actor, $employee)) {
            throw new ExpenseRuleViolation('You cannot file expenses for this employee.');
        }

        return $actor;
    }

    private function lockEmployee(Employee $employee): void
    {
        Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->getKey())->lockForUpdate()->value('id');
    }
}
