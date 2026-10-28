<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Compliance\Support\InvestmentCatalogue;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
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
 * to pending. Employees file their own (payroll.payslip); payroll.manage files for anyone in its scope.
 */
final class InvestmentDeclarations
{
    public function __construct(private readonly ComplianceRules $rules, private readonly FinancialYear $fy) {}

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

        return $declaration === null ? $user->can('create', EmployeeTaxDeclaration::class) : $user->can('update', $declaration);
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

        return collect(array_keys(InvestmentCatalogue::SECTIONS))->mapWithKeys(fn (string $key) => [$key => [
            'allowed' => $key === 'HRA_RENT' ? (bool) ($params['hra_exempt'] ?? false) : in_array($key, $allowed, true),
            'limit' => isset($limits[$key]) ? (float) $limits[$key] : null,
        ]])->all();
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
     * @param  array{regime: string, metro?: bool, previous_employer_income?: float|int|string|null, previous_employer_tds?: float|int|string|null, items: array<string, array{amount: float|int|string|null, description?: ?string, details?: ?array<string, mixed>}>}  $input
     */
    public function save(User $user, Employee $employee, string $financialYear, array $input, bool $submit): EmployeeTaxDeclaration
    {
        if (! array_key_exists($financialYear, $this->years())) {
            throw new RuntimeException('Choose a financial year from the list.');
        }
        if (! array_key_exists($input['regime'] ?? '', config('peopleos.compliance.tax_regimes'))) {
            throw new RuntimeException('Choose a tax regime.');
        }

        return DB::transaction(function () use ($user, $employee, $financialYear, $input, $submit) {
            // One save at a time per employee (the declaration may not exist yet, so lock the employee row).
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            $declaration = EmployeeTaxDeclaration::query()->where('employee_id', $employee->id)->where('financial_year', $financialYear)->lockForUpdate()->first();
            if (! $this->canEdit($user, $employee, $declaration)) {
                throw new RuntimeException($declaration?->status === 'verified' ? 'Payroll has verified this declaration. Ask payroll to make any change.' : 'You cannot change this declaration.');
            }

            $declaration ??= new EmployeeTaxDeclaration(['employee_id' => $employee->id, 'financial_year' => $financialYear]);
            $declaration->fill([
                'regime' => $input['regime'],
                'previous_employer_income' => $this->amount($input['previous_employer_income'] ?? 0),
                'previous_employer_tds' => $this->amount($input['previous_employer_tds'] ?? 0),
                'status' => $submit ? 'submitted' : 'draft',
            ]);
            if (! $declaration->exists) {
                $declaration->declarations = [];
                $declaration->withAuditReason('Investment declaration started')->save();
            }

            $rows = TdsEmployeeInvestment::query()->where('employee_id', $employee->id)->where('financial_year', $financialYear)->whereNotNull('item_key')->lockForUpdate()->get()->keyBy('item_key');
            $wanted = collect($input['items'] ?? [])
                ->filter(fn ($item, string $key) => InvestmentCatalogue::item($key) !== null && $this->amount($item['amount'] ?? 0) > 0);

            foreach ($rows as $key => $row) {
                if ($row->proof_status === 'verified') {
                    continue; // payroll accepted this proof: only payroll changes it, on the proofs screen
                }
                if (! $wanted->has($key)) {
                    $row->withAuditReason('Removed from the investment declaration')->delete();
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
                        ->withAuditReason('Declared in the investment declaration')->save();

                    continue;
                }
                $row->fill($values);
                if ($row->proof_status === 'rejected' && $row->isDirty(['declared_amount', 'description', 'details'])) {
                    $row->fill(['proof_status' => 'pending', 'proof_amount' => null, 'verified_by' => null, 'verified_at' => null]);
                }
                if ($row->isDirty()) {
                    $row->withAuditReason('Changed in the investment declaration')->save();
                }
            }

            $declaration->declarations = $this->totals($declaration->declarations ?? [], $employee, $financialYear, (bool) ($input['metro'] ?? false));
            $declaration->withAuditReason($submit ? 'Investment declaration submitted to payroll' : 'Investment declaration saved as draft')->save();

            return $declaration->refresh();
        });
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
