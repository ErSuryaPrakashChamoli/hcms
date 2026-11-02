<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Compliance\Support\InvestmentCatalogue;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Events\PayrollEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The guided investment declaration (§32 TDS): the employee ticks what they invested in or paid under each section
 * (InvestmentCatalogue) and declares an amount per item. Each item is a tds_employee_investments row (its proof is
 * verified there, item by item); the section totals that TaxComputer reads are written to the declaration from those
 * rows, so the two never disagree. Verified items are never changed here; a rejected item that is changed goes back
 * to pending. Employees file their own (payroll.payslip); payroll.manage files for anyone in its scope — HROne parity
 * AC-2: recorded as filed on the employee's behalf (audit reasons, and a SUBMITTED event with `on_behalf` on submission),
 * outside the declaration window too (payroll always may, as before).
 */
final class InvestmentDeclarations
{
    public function __construct(private readonly ComplianceRules $rules, private readonly FinancialYear $fy, private readonly TaxSettings $settings) {}

    /** The employees this user may declare for: their own employed record, or (payroll.manage) everyone employed in scope. */
    public function employeesFor(User $user): Builder
    {
        $query = Employee::query()->employed();

        return $user->hasPermission('payroll.manage') ? $query : $query->where('user_id', $user->id);
    }

    public function canDeclareFor(User $user, Employee $employee): bool
    {
        return $this->employeesFor($user)->whereKey($employee->id)->exists();
    }

    /** Whether the user may change this employee's declaration for the year (it may not exist yet). */
    public function canEdit(User $user, Employee $employee, ?EmployeeTaxDeclaration $declaration): bool
    {
        if (! $this->canDeclareFor($user, $employee)) {
            return false;
        }
        if (! $this->windowOpenFor($user)) {
            return false; // HP-3: outside the declaration window only payroll changes declarations
        }
        // HROne setup parity (PS-2): payroll may be kept to verifying a submitted declaration of someone else.
        if ($declaration !== null && $declaration->status !== 'draft' && (int) $employee->user_id !== (int) $user->id && ! $this->settings->payrollMayEdit()) {
            return false;
        }

        return $declaration === null ? $user->can('create', EmployeeTaxDeclaration::class) : $user->can('update', $declaration);
    }

    /** Whether the organisation's declaration window lets this user change declarations today (payroll always may). */
    public function windowOpenFor(User $user): bool
    {
        return $user->hasPermission('payroll.manage') || $this->settings->declarationOpen();
    }

    /** The financial years offered: last year, this year and next year. @return array<string, string> */
    public function years(): array
    {
        return collect([now()->subYear(), now(), now()->addYear()])->mapWithKeys(fn ($d) => [$this->fy->label($d) => $this->fy->label($d)])->all();
    }

    public function declaration(Employee $employee, string $financialYear): ?EmployeeTaxDeclaration
    {
        return EmployeeTaxDeclaration::query()->where('employee_id', $employee->id)->where('financial_year', $financialYear)->first();
    }

    /** @return Collection<int, TdsEmployeeInvestment> the employee's catalogue items for the year */
    public function items(Employee $employee, string $financialYear): Collection
    {
        return TdsEmployeeInvestment::query()->where('employee_id', $employee->id)->where('financial_year', $financialYear)->whereNotNull('item_key')->orderBy('id')->get();
    }

    /**
     * How payroll counts each section in the year under the regime, from the TDS rule in force at the start of the
     * year: whether the section is allowed at all and its limit (null = no limit).
     *
     * @return array<string, array{allowed: bool, limit: ?float}>
     */
    public function counting(string $financialYear, string $regime): array
    {
        $rule = $this->rules->resolve('TDS', $this->startOf($financialYear));
        $params = $rule?->param($regime) ?? $rule?->param('new') ?? [];
        $allowed = $params['allow_chapter_via'] ?? [];
        $limits = $params['limits'] ?? [];

        // HP-3: other income always counts (it adds to income); home-loan interest only when the rule carries its limit.
        return collect(array_keys(InvestmentCatalogue::SECTIONS))->mapWithKeys(fn (string $key) => [$key => match ($key) {
            'HRA_RENT' => ['allowed' => (bool) ($params['hra_exempt'] ?? false), 'limit' => null],
            'OTHER_INCOME' => ['allowed' => true, 'limit' => null],
            '24B' => ['allowed' => isset($params['house_property_interest_limit']), 'limit' => isset($params['house_property_interest_limit']) ? (float) $params['house_property_interest_limit'] : null],
            default => ['allowed' => in_array($key, $allowed, true), 'limit' => isset($limits[$key]) ? (float) $limits[$key] : null],
        }])->all();
    }

    /** PF deducted from salary so far in the year (it counts under 80C on its own). */
    public function pfSoFar(Employee $employee, string $financialYear): float
    {
        $start = $this->startOf($financialYear);
        $until = Carbon::parse($start)->addYear()->min(now()->addDay())->toDateString();

        return StatutoryEngine::yearToDate($employee->id, $start, $until)['pf_employee'];
    }

    /**
     * Save the declaration and its items.
     *
     * @param  array{regime: string, metro?: bool, previous_employer_income?: float|int|string|null, previous_employer_tds?: float|int|string|null, previous_employer?: ?array<string, mixed>, items: array<string, array{amount: float|int|string|null, description?: ?string, details?: ?array<string, mixed>}>}  $input
     */
    public function save(User $user, Employee $employee, string $financialYear, array $input, bool $submit): EmployeeTaxDeclaration
    {
        if (! array_key_exists($financialYear, $this->years())) {
            throw new RuntimeException('Choose a financial year from the list.');
        }
        if (! array_key_exists($input['regime'] ?? '', config('peopleos.compliance.tax_regimes'))) {
            throw new RuntimeException('Choose a tax regime.');
        }

        // HROne parity AC-2: payroll filing for the employee ("Declare for an employee") is recorded as on their behalf.
        $onBehalf = $employee->user_id === null || (int) $employee->user_id !== (int) $user->getKey();
        $by = $onBehalf ? ' (filed on the employee\'s behalf)' : '';

        return DB::transaction(function () use ($user, $employee, $financialYear, $input, $submit, $onBehalf, $by) {
            // One save at a time per employee (the declaration may not exist yet, so lock the employee row).
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            $declaration = EmployeeTaxDeclaration::query()->where('employee_id', $employee->id)->where('financial_year', $financialYear)->lockForUpdate()->first();
            if (! $this->canEdit($user, $employee, $declaration)) {
                throw new RuntimeException(match (true) {
                    $declaration?->status === 'verified' => 'Payroll has verified this declaration. Ask payroll to make any change.',
                    ! $this->windowOpenFor($user) => 'Declarations can be changed '.$this->settings->declarationWindow().'. Ask payroll to make any change.',
                    default => 'You cannot change this declaration.',
                });
            }
            if ($declaration !== null && $declaration->status !== 'draft' && $declaration->regime !== $input['regime']
                && $this->settings->regimeLockedAfterSubmit() && ! $user->hasPermission('payroll.manage')) {
                throw new RuntimeException('The tax regime cannot be changed once the declaration is submitted. Ask payroll to change it.');
            }
            $this->assertLandlords($input['items'] ?? []);

            $declaration ??= new EmployeeTaxDeclaration(['employee_id' => $employee->id, 'financial_year' => $financialYear]);
            $wasSubmitted = $declaration->exists && $declaration->getRawOriginal('status') === 'submitted';
            $declaration->fill([
                'regime' => $input['regime'],
                'previous_employer_income' => $this->amount($input['previous_employer_income'] ?? 0),
                'previous_employer_tds' => $this->amount($input['previous_employer_tds'] ?? 0),
                'previous_employer' => $this->previousEmployer($input['previous_employer'] ?? null),
                'status' => $submit ? 'submitted' : 'draft',
            ]);
            if (! $declaration->exists) {
                $declaration->declarations = [];
                $declaration->withAuditReason('Investment declaration started'.$by)->save();
            }

            $rows = TdsEmployeeInvestment::query()->where('employee_id', $employee->id)->where('financial_year', $financialYear)->whereNotNull('item_key')->lockForUpdate()->get()->keyBy('item_key');
            $wanted = collect($input['items'] ?? [])
                ->filter(fn ($item, string $key) => InvestmentCatalogue::item($key) !== null && $this->amount($item['amount'] ?? 0) > 0);

            foreach ($rows as $key => $row) {
                if ($row->proof_status === 'verified') {
                    continue; // payroll accepted this proof: only payroll changes it, on the proofs screen
                }
                if (! $wanted->has($key)) {
                    $row->withAuditReason('Removed from the investment declaration'.$by)->delete();
                }
            }

            foreach ($wanted as $key => $item) {
                $row = $rows->get($key);
                if ($row?->proof_status === 'verified') {
                    continue;
                }
                $section = explode('.', $key, 2)[0];
                $values = [
                    'employee_tax_declaration_id' => $declaration->id,
                    'section' => $section,
                    'description' => InvestmentCatalogue::isOther($key) && filled($item['description'] ?? null) ? mb_substr(trim($item['description']), 0, 255) : InvestmentCatalogue::item($key)['label'],
                    'details' => $this->details($item['details'] ?? null),
                    'declared_amount' => $this->amount($item['amount']),
                ];
                if ($row === null) {
                    (new TdsEmployeeInvestment($values + ['employee_id' => $employee->id, 'financial_year' => $financialYear, 'item_key' => $key, 'proof_status' => 'pending']))
                        ->withAuditReason('Declared in the investment declaration'.$by)->save();

                    continue;
                }
                $row->fill($values);
                if ($row->proof_status === 'rejected' && $row->isDirty(['declared_amount', 'description', 'details'])) {
                    $row->fill(['proof_status' => 'pending', 'proof_amount' => null, 'verified_by' => null, 'verified_at' => null]);
                }
                if ($row->isDirty()) {
                    $row->withAuditReason('Changed in the investment declaration'.$by)->save();
                }
            }

            $declaration->declarations = $this->totals($declaration->declarations ?? [], $employee, $financialYear, (bool) ($input['metro'] ?? false));
            $declaration->withAuditReason(($submit ? 'Investment declaration submitted to payroll' : 'Investment declaration saved as draft').$by)->save();
            if ($submit && $onBehalf) {
                app(AuditRecorder::class)->record(AuditAction::Submitted, 'compliance', $declaration, reason: 'Investment declaration submitted on behalf of the employee', actor: $user,
                    metadata: ['on_behalf' => true, 'employee_id' => (int) $employee->getKey(), 'financial_year' => $financialYear]);
            }
            // HROne setup parity P5: a declaration newly submitted to payroll is an event (its approval workflow, when one is set up).
            if ($submit && ! $wasSubmitted) {
                $this->event('payroll.tax_declaration_submitted', $declaration);
            }

            return $declaration->refresh();
        });
    }

    /**
     * Data import DI-4: the employee's declaration for the year as save() input, the way the guided page loads it (every
     * item with its amount, description and details; a section total declared before items existed in the section's
     * catch-all item; metro; the previous employer), so an upload can change one part and save the rest unchanged.
     *
     * @return array{regime: string, metro: bool, previous_employer_income: float, previous_employer_tds: float, previous_employer: ?array<string, mixed>, items: array<string, array{amount: float, description: ?string, details: ?array<string, mixed>}>}
     */
    public function currentInput(Employee $employee, string $financialYear): array
    {
        $declaration = $this->declaration($employee, $financialYear);
        $items = [];
        $rows = $this->items($employee, $financialYear);
        foreach ($rows as $row) {
            $items[(string) $row->item_key] = ['amount' => (float) $row->declared_amount, 'description' => $row->description, 'details' => $row->details];
        }
        foreach (array_keys(InvestmentCatalogue::SECTIONS) as $section) {
            $total = (float) ($declaration?->amount($section) ?? 0);
            if ($total > 0 && ! $rows->contains('section', $section)) {
                $items[InvestmentCatalogue::fallbackItem($section)] = ['amount' => $total, 'description' => 'Declared earlier', 'details' => null];
            }
        }

        return [
            'regime' => (string) ($declaration?->regime ?? 'new'),
            'metro' => (float) ($declaration?->amount('METRO') ?? 0) > 0,
            'previous_employer_income' => (float) ($declaration?->previous_employer_income ?? 0),
            'previous_employer_tds' => (float) ($declaration?->previous_employer_tds ?? 0),
            'previous_employer' => $declaration?->previous_employer ?: null,
            'items' => $items,
        ];
    }

    /**
     * HROne setup parity P5: payroll verifies a submitted declaration (here: the outcome of its approval workflow, in the
     * name of the person who completed the last step). Nobody verifies their own.
     */
    public function verify(EmployeeTaxDeclaration $declaration, User $actor, ?string $note = null): EmployeeTaxDeclaration
    {
        return DB::transaction(function () use ($declaration, $actor, $note) {
            $locked = $this->decidable($declaration, $actor);
            $locked->withAuditReason($note ?: 'Proofs verified')->update(['status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()]);
            $this->event('payroll.tax_declaration_verified', $locked);

            return $locked;
        });
    }

    /** HROne setup parity P5: a submitted declaration goes back to the employee as a draft to change and submit again. */
    public function returnToEmployee(EmployeeTaxDeclaration $declaration, User $actor, string $note): EmployeeTaxDeclaration
    {
        if (trim($note) === '') {
            throw new RuntimeException('Say what the employee should change.');
        }

        return DB::transaction(function () use ($declaration, $actor, $note) {
            $locked = $this->decidable($declaration, $actor);
            $locked->withAuditReason($note)->update(['status' => 'draft']);
            $this->event('payroll.tax_declaration_returned', $locked);

            return $locked;
        });
    }

    private function decidable(EmployeeTaxDeclaration $declaration, User $actor): EmployeeTaxDeclaration
    {
        $locked = EmployeeTaxDeclaration::query()->whereKey($declaration->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'submitted') {
            throw new RuntimeException('This declaration is not waiting for payroll.');
        }
        if ((int) Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($locked->employee_id)->value('user_id') === (int) $actor->id) {
            throw new RuntimeException('Nobody verifies their own declaration.');
        }

        return $locked;
    }

    /** Payroll events of the declaration: references only (financial year, regime, status, the employee). */
    private function event(string $name, EmployeeTaxDeclaration $declaration): void
    {
        PayrollEvent::dispatch($name, $declaration, ['financial_year' => $declaration->financial_year, 'regime' => $declaration->regime, 'status' => $declaration->status, 'employee_id' => (int) $declaration->employee_id]);
    }

    /**
     * Section totals from the items (what TaxComputer reads). Keys the catalogue does not own (e.g. 80CCD2 entered by
     * payroll) are kept; METRO is set only when rent is declared.
     *
     * @param  array<string, mixed>  $existing
     * @return array<string, float|int>
     */
    private function totals(array $existing, Employee $employee, string $financialYear, bool $metro): array
    {
        $owned = [...array_keys(InvestmentCatalogue::SECTIONS), 'METRO'];
        $totals = collect($existing)->except($owned)->all();
        $sums = TdsEmployeeInvestment::query()->where('employee_id', $employee->id)->where('financial_year', $financialYear)->whereNotNull('item_key')->get()
            ->groupBy('section')->map(fn (Collection $rows) => round($rows->sum(fn ($r) => (float) $r->declared_amount), 2));
        foreach (array_keys(InvestmentCatalogue::SECTIONS) as $section) {
            if (($sums[$section] ?? 0) > 0) {
                $totals[$section] = $sums[$section];
            }
        }
        if (($totals['HRA_RENT'] ?? 0) > 0) {
            $totals['METRO'] = $metro ? 1 : 0;
        }

        return $totals;
    }

    /**
     * HP-3 (PY-13): previous-employer details for Form 12B / 12BB; amounts are kept as numbers, empty values dropped.
     *
     * @param  array<string, mixed>|null  $details
     * @return array<string, mixed>|null
     */
    private function previousEmployer(?array $details): ?array
    {
        // HROne parity MS-4: the gratuity and leave-encashment exemptions used there (PriorExemptions; counted only through a verified rule).
        $clean = $this->details(collect($details ?? [])->only(['name', 'tan', 'from', 'to', 'pf', 'pt', 'gratuity_exempt', 'leave_encashment_exempt'])->all()) ?? [];
        foreach (['pf', 'pt', 'gratuity_exempt', 'leave_encashment_exempt'] as $key) {
            if (isset($clean[$key])) {
                $clean[$key] = $this->amount($clean[$key]);
            }
        }
        if (isset($clean['tan'])) {
            $clean['tan'] = strtoupper((string) $clean['tan']);
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * HP-3 (PY-13): every landlord on a rent declaration needs a PAN when the organisation asks for it, and no more
     * landlords than it allows.
     *
     * @param  array<string, array<string, mixed>>  $items
     */
    private function assertLandlords(array $items): void
    {
        $rent = $items['HRA_RENT.rent'] ?? null;
        if ($rent === null || $this->amount($rent['amount'] ?? 0) <= 0) {
            return;
        }
        $others = array_values(array_filter((array) ($rent['details']['landlords'] ?? []), fn ($l) => filled($l['name'] ?? null) || filled($l['pan'] ?? null)));
        if (count($others) + 1 > $this->settings->maxLandlords()) {
            throw new RuntimeException('Rent can be declared for at most '.$this->settings->maxLandlords().' landlord(s).');
        }
        if ($this->settings->landlordPanRequired()) {
            foreach ([$rent['details'] ?? [], ...$others] as $landlord) {
                if (blank($landlord['landlord_pan'] ?? $landlord['pan'] ?? null)) {
                    throw new RuntimeException('Give the PAN of every landlord you pay rent to.');
                }
            }
        }
    }

    /** @param  array<string, mixed>|null  $details */
    private function details(?array $details): ?array
    {
        $details = collect($details ?? [])->map(fn ($v) => is_string($v) ? trim($v) : $v)->filter(fn ($v) => $v !== null && $v !== '')->all();

        return $details === [] ? null : $details;
    }

    private function amount(float|int|string|null $value): float
    {
        return max(0, round((float) $value, 2));
    }

    private function startOf(string $financialYear): string
    {
        return Carbon::create((int) substr($financialYear, 0, 4), (int) config('peopleos.compliance.financial_year_start_month', 4), 1)->toDateString();
    }
}
