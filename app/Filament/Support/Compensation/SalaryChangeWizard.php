<?php

namespace App\Filament\Support\Compensation;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\SalaryAssignments;
use App\Domain\Compensation\Services\SalaryRevisions;
use App\Domain\Compensation\Support\CompensationSnapshot;
use App\Domain\Employment\Actions\ChangeStatutoryApplicabilityAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Letters\Services\Letters;
use App\Domain\Payroll\Contracts\SalaryArrearsWriter;
use App\Domain\Payroll\Services\SalaryProposalPreview;
use App\Filament\Resources\CompensationChanges\CompensationChangeResource;
use App\Filament\Resources\EmployeePaymentModes\EmployeePaymentModeResource;
use App\Filament\Support\Review\Outcome;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * One employee's salary change as a four-step wizard (owner, 2026-11-27), shared by Employee 360 → Current salary and the
 * salary structures register: Employee statutory (PF, ESI, PT, tax regime; changed through the Employee 360 action) →
 * Salary details → Compare (every component before → after with its percentage, the deductions, net pay, employer
 * contributions and CTC, the statutory estimate and the salary checks) → Review.
 *
 * Three purposes: "Assign salary" (the first salary), "Revise salary" (a new salary from a date: a hike % or a new amount
 * on CTC, gross pay or net pay — SalaryRevisions works gross and net out through the structure's formulas) and "Edit salary
 * in force" (a correction on the same date that replaces the salary in force). Every one only PROPOSES through
 * SalaryAssignments → CompensationChanges: a reviewer, an approver and an executor (other people) decide it, and the
 * audit trail keeps each step.
 */
final class SalaryChangeWizard
{
    public const PURPOSES = ['assign' => 'Assign salary', 'revise' => 'Revise salary', 'correct' => 'Edit salary in force'];

    /** Keys of the form the services and the review read. */
    public const FORM_KEYS = ['change_type', 'effective_from', 'salary_structure_id', 'basis', 'ctc_mode', 'ctc_annual', 'target_monthly', 'increase_percent', 'currency',
        'variable_target_annual', 'component_values', 'reason', 'internal_notes', 'submit', 'change_statutory'];

    /** Assign for an employee who never had a salary, else revise. */
    public static function purposeFor(Employee $employee): string
    {
        return app(CompensationOutput::class)->history($employee)->isEmpty() ? 'assign' : 'revise';
    }

    /** The salary an edit corrects, or a revision starts from: the one in force today, else the latest approved one. */
    public static function base(Employee $employee): ?CompensationSnapshot
    {
        $output = app(CompensationOutput::class);

        return $output->on($employee, today()) ?? $output->history($employee)->last();
    }

    /** Seeing the statutory details is a sensitive read, audited as on Employee 360. */
    public static function auditOpening(Employee $employee, string $purpose): void
    {
        if (auth()->user()?->can('viewSensitive', $employee)) {
            app(SensitiveAccessAuditor::class)->recordView($employee, 'statutory', $purpose === 'assign' ? 'assign_salary' : $purpose.'_salary');
        }
    }

    /** @return list<Step> */
    public static function steps(Employee $record, string $purpose): array
    {
        $current = $purpose === 'assign' ? null : self::base($record);
        $label = fn (string $purpose) => match ($purpose) {
            'assign' => 'Structure, amount and the date', 'correct' => 'The salary in force, corrected', default => 'Hike % or new amount, on CTC, gross or net',
        };

        return [
            Step::make('Employee statutory')->description('PF, ESI, PT and tax regime')->schema(self::statutorySchema($record)),
            Step::make('Salary details')->description($label($purpose))->schema(self::fields($record, $current, $purpose)),
            Step::make($purpose === 'assign' ? 'Breakup' : 'Compare')->description($purpose === 'assign' ? 'Breakup, deductions estimate, checks' : 'Before → after, line by line')->schema([
                View::make('filament.compensation.salary-change-preview')->columnSpanFull()
                    ->viewData(fn (Get $get) => self::previewData($record, self::formState($get), $purpose)),
                Actions::make([
                    Action::make('printBreakup')->label('Print breakup')->icon(Heroicon::OutlinedPrinter)->color('gray')
                        ->action(fn (Get $get) => self::printProposal((int) $record->id, self::formState($get), $purpose)),
                ]),
            ]),
            Step::make('Review')->description('What will be proposed')->schema([
                Text::make(fn (Get $get) => self::reviewOutcome($record, self::formState($get), $purpose)),
            ]),
        ];
    }

    /**
     * Step 2. "Revise on" CTC (a year), gross pay or net pay (a month); "How" a hike %, a new amount or (a structure change
     * only) the salary in force kept. An edit keeps the date of the salary in force and is a correction.
     *
     * @return list<mixed>
     */
    public static function fields(Employee $record, ?CompensationSnapshot $current, string $purpose): array
    {
        $correct = $purpose === 'correct';
        $currencies = (array) config('peopleos.compensation.currencies', []);
        $base = app(CurrencyRates::class)->baseCurrency();
        $modes = match (true) {
            $current === null => ['amount' => 'Amount'],
            $correct => ['amount' => 'New amount', 'percent' => 'Change by %'],
            default => ['percent' => 'Hike %', 'amount' => 'New amount', 'keep' => 'Keep the salary in force (structure change only)'],
        };
        $basis = fn (Get $get): string => array_key_exists((string) $get('basis'), SalaryRevisions::BASES) ? (string) $get('basis') : 'ctc';
        $word = fn (Get $get): string => $basis($get) === 'ctc' ? 'CTC' : mb_strtolower(SalaryRevisions::BASES[$basis($get)]);

        return [
            Grid::make(['default' => 1, 'md' => 2])->schema([
                Select::make('change_type')->label('Change type')->options((array) config('peopleos.compensation.change_types'))->required()
                    ->default(match ($purpose) {
                        'assign' => 'hire', 'correct' => 'correction', default => 'revision'
                    })
                    ->disabled($correct)->dehydrated(),
                DatePicker::make('effective_from')->label($correct ? 'Salary in force since' : 'Salary effective from')->native(false)->required()
                    ->default(self::defaultDate($record, $current, $purpose))
                    ->disabled($correct)->dehydrated()->live()
                    ->helperText($correct ? 'An edit replaces the salary in force from its own date; to change it from a later date, revise it instead.' : null),
                Select::make('salary_structure_id')->label('Salary structure (pay group)')->required()->live()
                    ->options(($current !== null ? ['keep' => 'Keep the structure in force ('.$current->structureCode.')'] : [])
                        + SalaryStructure::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                    ->default($current !== null ? 'keep' : null),
                Radio::make('basis')->label($purpose === 'revise' ? 'Revise on' : 'Give the salary as')->options(SalaryRevisions::BASES)->default('ctc')->required()->inline()->live()
                    ->helperText('CTC is a year; gross and net pay are a month. On gross or net pay the structure\'s own formulas work out the CTC that gives it.'),
                Radio::make('ctc_mode')->label('How')->options($modes)->required()->live()->inline()
                    ->default($purpose === 'revise' ? 'percent' : 'amount'),
                // Owner, 2026-11-28: the hike is decided while looking at the salary it changes, so every figure that moves it
                // refreshes the "now → new" panel under it as it is typed.
                TextInput::make('increase_percent')->label(fn (Get $get) => ($correct ? 'Change in ' : 'Hike on ').$word($get).' (%)')
                    ->numeric()->minValue(-99.99)->maxValue(1000)->suffix('%')->live(debounce: 500)
                    ->visible(fn (Get $get) => $get('ctc_mode') === 'percent')->required(fn (Get $get) => $get('ctc_mode') === 'percent')
                    ->helperText(fn (Get $get) => $current === null ? null : 'A percentage of the '.$word($get).' in force on the effective date; the salary in force and the new one are shown below.'),
                TextInput::make('ctc_annual')->label('Annual CTC')->numeric()->minValue(1)->maxValue(999999999999)->live(debounce: 600)
                    ->visible(fn (Get $get) => $get('ctc_mode') === 'amount' && $basis($get) === 'ctc')
                    ->helperText('A structure built from component amounts works its CTC out from them instead.')
                    ->default($current?->ctcAnnual),
                TextInput::make('target_monthly')->label(fn (Get $get) => 'New '.$word($get).' (a month)')->numeric()->minValue(1)->maxValue(99999999999)->live(debounce: 600)
                    ->visible(fn (Get $get) => $get('ctc_mode') === 'amount' && $basis($get) !== 'ctc')
                    ->required(fn (Get $get) => $get('ctc_mode') === 'amount' && $basis($get) !== 'ctc')
                    ->helperText('The CTC (or the component amounts) that give this pay is worked out from the structure\'s formulas.'),
                Select::make('currency')->options(array_combine($currencies, $currencies) ?: [])->required(fn (Get $get) => $get('ctc_mode') === 'amount')
                    ->visible(fn (Get $get) => $get('ctc_mode') === 'amount')->default($current?->currency ?? $base)->live(),
                View::make('filament.compensation.salary-change-impact')->columnSpanFull()
                    ->visible(fn (Get $get) => filled($get('ctc_mode')))
                    ->viewData(fn (Get $get) => self::impact($record, self::formState($get), $purpose)),
                TextInput::make('variable_target_annual')->label('Variable pay target (a year)')->numeric()->minValue(0)->default($current?->variableTargetAnnual)
                    ->helperText('Paid through variable pay plans; empty for none.'),
            ]),
            Section::make('Fixed amounts and inputs')->description('For components calculated as "fixed" or "input", e.g. CONV = 1600. Prefilled from the salary in force.')
                ->collapsible()->collapsed()->schema([
                    KeyValue::make('component_values')->hiddenLabel()->keyLabel('Component code')->valueLabel('Amount (a month)')
                        ->default($current?->componentValues ?? []),
                ]),
            Textarea::make('reason')->label('Reason (shown with the change)')->required()->maxLength(2000),
            Textarea::make('internal_notes')->label('Internal notes (never shown to the employee)')->maxLength(2000),
            Toggle::make('submit')->label('Submit for review now')->default(true)
                ->helperText('Off: saved as a draft you submit later. Either way a reviewer, an approver and an executor (other people) decide it.'),
        ];
    }

    /** A first salary from the joining date (this month at the earliest); a revision from the first of next month; an edit from its own date. */
    private static function defaultDate(Employee $record, ?CompensationSnapshot $current, string $purpose): string
    {
        return match ($purpose) {
            'correct' => ($current?->effectiveFrom ?? today())->toDateString(),
            'assign' => $record->joining_date !== null ? $record->joining_date->copy()->max(today()->startOfMonth())->toDateString() : today()->toDateString(),
            default => now()->startOfMonth()->addMonth()->toDateString(),
        };
    }

    /** @return list<mixed> step 1: the statutory details as Employee 360 shows them, changed through its action */
    public static function statutorySchema(Employee $record): array
    {
        $user = auth()->user();
        if (! $user->can('viewSensitive', $record)) {
            return [Text::make('Statutory details need employee.sensitive.view. Payroll applies what Employee 360 holds (PF, ESI, PT and the tax regime); ask HR to check them before the salary takes effect.')];
        }
        $detail = $record->statutoryDetail()->first();
        $html = '<dl class="grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2" data-statutory-summary>'
            .collect(self::statutoryFacts($record))->map(fn ($v, $k) => '<div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">'.e($k).'</dt><dd>'.e($v).'</dd></div>')->implode('')
            .'</dl>'.($detail === null ? '<p class="mt-2 text-sm text-warning-600">No statutory details on file: payroll applies PF and PT by default.</p>' : '');
        $schema = [Text::make(new HtmlString($html))];
        if (! $user->can('updateSensitive', $record)) {
            return [...$schema, Text::make('Changing statutory details needs employee.sensitive.update.')];
        }

        return [...$schema,
            Toggle::make('change_statutory')->label('Change statutory details')->default(false)->live(),
            Grid::make(['default' => 1, 'md' => 2])->visible(fn (Get $get) => (bool) $get('change_statutory'))->schema([
                Toggle::make('pf_applicable')->label('PF applicable')->default($detail?->pf_applicable ?? true),
                Toggle::make('esic_applicable')->label('ESI applicable')->default((bool) ($detail?->esic_applicable ?? false)),
                Toggle::make('pt_applicable')->label('PT applicable')->default($detail?->pt_applicable ?? true),
                Select::make('tax_regime')->label('Tax regime')->options(['old' => 'Old regime', 'new' => 'New regime'])->default($detail?->tax_regime),
                TextInput::make('pt_state_code')->label('PT state')->maxLength(16)->default($detail?->pt_state_code),
                Textarea::make('statutory_reason')->label('Reason for change')->required(fn (Get $get) => (bool) $get('change_statutory'))->maxLength(1000)->columnSpanFull(),
            ]),
        ];
    }

    /**
     * The statutory details payroll applies, numbers never shown (only whether they are on file).
     *
     * @return array<string, string>
     */
    public static function statutoryFacts(Employee $record): array
    {
        $detail = $record->statutoryDetail()->first();
        $yes = fn (?bool $value) => $value === null ? '—' : ($value ? 'Yes' : 'No');
        $onFile = fn (?string $value) => filled($value) ? 'On file' : 'Missing';

        return [
            'PF applicable' => $detail === null ? 'Yes (default)' : $yes($detail->pf_applicable), 'ESI applicable' => $yes($detail?->esic_applicable),
            'PT applicable' => $detail === null ? 'Yes (default)' : $yes($detail->pt_applicable),
            'PT state' => $detail?->pt_state_code ?: '—', 'Tax regime (statutory details)' => $detail?->tax_regime ? ucfirst((string) $detail->tax_regime) : '—',
            'PAN' => $onFile($detail?->pan), 'UAN' => $onFile($detail?->uan), 'ESI number' => $onFile($detail?->esic_number),
        ];
    }

    /**
     * The statutory details as states for the Current salary card: whether PF, ESI and PT apply (yes / no / not set, and
     * whether that is payroll's default), the PT state and tax regime, and which numbers are on file (never the numbers).
     *
     * @return array{applies: array<string, array{state: string, default: bool}>, pt_state: ?string, regime: ?string, ids: array<string, bool>, recorded: bool}
     */
    public static function statutoryDetails(Employee $record): array
    {
        $detail = $record->statutoryDetail()->first();
        $state = fn (?bool $value) => $value === null ? 'unset' : ($value ? 'yes' : 'no');

        return [
            'applies' => [
                'PF' => ['state' => $detail === null ? 'yes' : $state($detail->pf_applicable), 'default' => $detail === null],
                'ESI' => ['state' => $state($detail?->esic_applicable), 'default' => false],
                'PT' => ['state' => $detail === null ? 'yes' : $state($detail->pt_applicable), 'default' => $detail === null],
            ],
            'pt_state' => $detail?->pt_state_code ?: null,
            'regime' => $detail?->tax_regime ? ucfirst((string) $detail->tax_regime).' regime' : null,
            'ids' => ['PAN' => filled($detail?->pan), 'UAN' => filled($detail?->uan), 'ESI number' => filled($detail?->esic_number)],
            'recorded' => $detail !== null,
        ];
    }

    /** @return array<string, mixed> the form's values the services read, plus the statutory toggle */
    public static function formState(Get $get): array
    {
        $state = [];
        foreach (self::FORM_KEYS as $key) {
            $state[$key] = $get($key);
        }
        $state['component_values'] = self::keyValues($state['component_values']);

        return $state;
    }

    /**
     * A key-value field's amounts by component code. While the form is open the field holds rows ({key, value}); its saved
     * state is code => amount. Either way the services get code => amount.
     *
     * @return array<string, mixed>
     */
    public static function keyValues(mixed $state): array
    {
        $out = [];
        foreach ((array) $state as $key => $row) {
            if (is_array($row) && array_key_exists('key', $row)) {
                if (filled($row['key'])) {
                    $out[trim((string) $row['key'])] = $row['value'] ?? null;
                }
            } elseif (! is_array($row)) {
                $out[(string) $key] = $row;
            }
        }

        return $out;
    }

    /**
     * The compare step: the proposal worked out (a gross or net target solved), the breakup for this employee, before →
     * after with every line's change, the read-only statutory estimate, the salary checks and the payment mode — or why
     * the proposal would be refused.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function previewData(Employee $employee, array $state, string $purpose = 'assign'): array
    {
        $user = auth()->user();
        $assignments = app(SalaryAssignments::class);
        $revisions = app(SalaryRevisions::class);
        $state = self::forPurpose($employee, $state, $purpose);
        try {
            if (($refusal = $assignments->refusal($employee, $user)) !== null) {
                throw new RuntimeException($refusal);
            }
            $resolved = $revisions->resolve($employee, $state);
            $proposal = $assignments->check($employee, $resolved['input'], $user);
            $segment = $assignments->segment($employee, $proposal) ?? throw new RuntimeException('The structure has no approved version on the effective date.');
            $result = app(SalaryProposalPreview::class)->for($employee, $segment);
            $current = app(CompensationOutput::class)->on($employee, $proposal['effective_from']);
            $before = $current !== null ? $revisions->segmentFor($employee, $current, $proposal['effective_from']) : null;
            $compare = $revisions->compare($employee, $before, $segment, $result);
        } catch (RuntimeException $e) {
            return ['failure' => 'This proposal would be refused: '.$e->getMessage(), 'result' => null, 'proposal' => null, 'current' => null, 'compare' => null, 'solved' => null, 'payModesUrl' => null, 'purpose' => $purpose];
        } catch (Throwable $e) {
            report($e);

            return ['failure' => 'The preview could not be worked out from these values.', 'result' => null, 'proposal' => null, 'current' => null, 'compare' => null, 'solved' => null, 'payModesUrl' => null, 'purpose' => $purpose];
        }

        return [
            'failure' => null, 'result' => $result, 'proposal' => $proposal, 'current' => $current, 'compare' => $compare, 'solved' => $resolved['solved'], 'purpose' => $purpose,
            'payModesUrl' => EmployeePaymentModeResource::canViewAny() ? EmployeePaymentModeResource::getUrl('index', ['tableSearch' => $employee->employee_code]) : null,
            'arrears' => self::arrears($employee, $proposal, $compare),
        ];
    }

    /**
     * Owner, 2026-11-28: "the comparison is to decide the hike wisely — it must be on the same page". Under the hike (or
     * the new amount) on Salary details: CTC a year, gross and net pay a month, as in force on the effective date → with
     * this change, the change and its percentage; the row the change is given on stands out. Before a hike or an amount
     * is entered, the salary in force alone. The Compare step keeps the line-by-line breakup.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function impact(Employee $employee, array $state, string $purpose = 'assign'): array
    {
        $state = self::forPurpose($employee, $state, $purpose);
        $basis = array_key_exists((string) ($state['basis'] ?? ''), SalaryRevisions::BASES) ? (string) $state['basis'] : 'ctc';
        $mode = (string) ($state['ctc_mode'] ?? '');
        $given = match ($mode) {
            'percent' => is_numeric($state['increase_percent'] ?? null),
            'amount' => is_numeric($basis === 'ctc' ? ($state['ctc_annual'] ?? null) : ($state['target_monthly'] ?? null)),
            'keep' => true,
            default => false,
        };
        // The figures only: the reason is typed below the hike, so it is not asked for yet (Next still requires it).
        $preview = $given ? self::previewData($employee, ['reason' => filled($state['reason'] ?? null) ? $state['reason'] : 'Working out the figures'] + $state, $purpose) : null;
        $headline = $preview['compare']['headline'] ?? null;
        $currency = (string) ($preview['compare']['currency'] ?? '');

        // The salary in force on the effective date, worked out on its own when there is no proposal to compare yet.
        $before = null;
        if ($headline !== null) {
            $before = $headline['gross_before'] !== null ? ['ctc' => $headline['ctc_before'], 'gross' => $headline['gross_before'], 'net' => $headline['net_before']] : null;
        } elseif ($purpose !== 'assign') {
            try {
                $date = Carbon::parse($state['effective_from'] ?? today())->startOfDay();
                $revisions = app(SalaryRevisions::class);
                $snapshot = app(CompensationOutput::class)->on($employee, $date);
                $segment = $snapshot !== null ? $revisions->segmentFor($employee, $snapshot, $date) : null;
                if ($segment !== null) {
                    $alone = $revisions->compare($employee, null, $segment);
                    $before = ['ctc' => $alone['headline']['ctc_annual'], 'gross' => $alone['headline']['gross_monthly'], 'net' => $alone['headline']['net_monthly']];
                    $currency = $alone['currency'];
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
        $after = $headline !== null ? ['ctc' => $headline['ctc_annual'], 'gross' => $headline['gross_monthly'], 'net' => $headline['net_monthly']] : null;

        $rows = [];
        foreach (['ctc' => 'Annual CTC', 'gross' => 'Gross pay a month', 'net' => 'Net pay a month (take-home)'] as $key => $label) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            $rows[] = [
                'key' => $key, 'label' => $label, 'before' => $old, 'after' => $new, 'given' => $key === $basis && $mode !== 'keep',
                'change' => $old !== null && $new !== null ? round($new - $old, 2) : null,
                'percent' => $old !== null && $new !== null && abs($old) >= 0.005 ? round(($new - $old) / $old * 100, 2) : null,
            ];
        }
        $word = $basis === 'ctc' ? 'CTC' : mb_strtolower(SalaryRevisions::BASES[$basis]);

        return [
            'purpose' => $purpose, 'basis' => $basis, 'mode' => $mode, 'given' => $given, 'currency' => $currency ?: null, 'rows' => $rows,
            'hasBefore' => $before !== null, 'hasAfter' => $after !== null, 'complete' => (bool) ($preview['compare']['complete'] ?? true),
            'failure' => $preview['failure'] ?? null, 'solved' => $preview['solved'] ?? null, 'arrears' => $preview['arrears'] ?? null,
            'effective' => filled($state['effective_from'] ?? null) ? Carbon::parse($state['effective_from'])->format('j M Y') : null,
            'hint' => match (true) {
                $given => null,
                $mode === 'percent' => 'Type the hike % to see the new salary beside the one in force.',
                default => 'Enter the new '.$word.' to see the salary it gives.',
            },
        ];
    }

    /**
     * Owner, 2026-11-27: a date in a month whose payroll is finalised. Which closed months the change reaches, the month
     * that pays their difference, and a rough figure (the change in gross pay a month × the months reached, from the
     * date) — the exact arrears are worked out per pay item, on each month's attendance, when the change takes effect.
     * Null when no closed month is reached.
     *
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>|null  $compare
     * @return array<string, mixed>|null
     */
    public static function arrears(Employee $employee, array $proposal, ?array $compare): ?array
    {
        $writer = app(SalaryArrearsWriter::class);
        $from = Carbon::parse($proposal['effective_from'])->startOfDay();
        $reach = $writer->reach((int) $employee->id, $from);
        if ($reach === null) {
            return null;
        }
        $months = 0.0;
        foreach ($reach['months'] as $month) {
            [$start, $end] = [Carbon::parse($month['start']), Carbon::parse($month['end'])];
            $months += ($start->max($from)->diffInDays($end) + 1) / ($start->diffInDays($end) + 1);
        }
        $monthly = (float) ($compare['headline']['gross_change'] ?? 0.0);

        return $reach + [
            'enabled' => $writer->enabled(), 'first' => $reach['months'][0]['label'], 'last' => end($reach['months'])['label'], 'count' => count($reach['months']),
            'monthly' => $monthly, 'estimate' => round($monthly * $months, 2), 'currency' => (string) ($compare['currency'] ?? $proposal['currency']),
            'through_label' => Carbon::parse($reach['through'])->format('j M Y'),
        ];
    }

    /** "Print breakup": the proposal's salary annexure as a printable page in the letter studio's layout (audited). */
    public static function printProposal(int $employeeId, array $state, string $purpose = 'assign'): ?StreamedResponse
    {
        $user = auth()->user();
        $employee = Employee::query()->with('person')->whereKey($employeeId)->first();
        abort_unless($employee !== null && $user->hasPermission('compensation.propose') && app(AccessScopes::class)->allows($user, $employee), 403);
        $assignments = app(SalaryAssignments::class);
        try {
            $resolved = app(SalaryRevisions::class)->resolve($employee, self::forPurpose($employee, $state, $purpose));
            $proposal = $assignments->check($employee, $resolved['input'], $user);
            $segment = $assignments->segment($employee, $proposal) ?? throw new RuntimeException('The structure has no approved version on the effective date.');
            $file = app(Letters::class)->breakup($employee, $segment, $user);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Breakup not printed')->body($e->getMessage())->persistent()->send();

            return null;
        }

        return response()->streamDownload(fn () => print ($file['content']), $file['name'], ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Review: what proposing does, as an outcome card (the change in one sentence, the structure and the CTC before →
     * after, the percentages on CTC, gross and net pay, the statutory details, what comes next) — or why the proposal
     * would be refused.
     *
     * @param  array<string, mixed>  $state
     */
    public static function reviewOutcome(Employee $employee, array $state, string $purpose = 'assign'): Outcome
    {
        $user = auth()->user();
        $outcome = Outcome::make('What happens when you propose');
        $state = self::forPurpose($employee, $state, $purpose);
        try {
            if (($refusal = app(SalaryAssignments::class)->refusal($employee, $user)) !== null) {
                throw new RuntimeException($refusal);
            }
            $resolved = app(SalaryRevisions::class)->resolve($employee, $state);
            $proposal = app(SalaryAssignments::class)->check($employee, $resolved['input'], $user);
        } catch (RuntimeException $e) {
            return $outcome->data('review', 'refused')->status('blocked', 'Would be refused')
                ->headline('This proposal would be refused.')
                ->problems('blocked', 'Go back and fix this', [$e->getMessage()], 'Nothing is proposed.');
        }
        $current = app(CompensationOutput::class)->on($employee, $proposal['effective_from']);
        $structure = SalaryStructure::query()->find($proposal['salary_structure_id']);
        $change = (string) config("peopleos.compensation.change_types.{$proposal['change_type']}", $proposal['change_type']);
        $from = Carbon::parse($proposal['effective_from'])->format('j M Y');
        $ctc = $proposal['currency'].' '.number_format((float) $proposal['ctc_annual'], 2);
        $submit = (bool) ($state['submit'] ?? true);
        $solved = $resolved['solved'];

        $outcome->data('review', 'ok')->status('clear', 'Ready to propose')
            ->headline($change.' for '.$employee->auditLabel().' from '.$from.': annual CTC '.$ctc.'.',
                $purpose === 'correct' ? 'It replaces the salary in force from its own date once approved and executed.' : null)
            ->fact('Change', $change)
            ->fact('Effective from', $from);
        if ($current !== null && $current->structureId !== (int) $proposal['salary_structure_id']) {
            $outcome->change('Salary structure', $current->structureCode, $structure?->name);
        } else {
            $outcome->fact('Salary structure', $structure?->name ?? '—');
        }
        if ($current !== null) {
            $outcome->change('Annual CTC', $current->currency.' '.number_format($current->ctcAnnual, 2), $ctc,
                $proposal['increase_percent'] !== null ? ($proposal['increase_percent'] >= 0 ? '+' : '').$proposal['increase_percent'].'% on the CTC in force' : null);
        } else {
            $outcome->fact('Annual CTC', $ctc);
        }
        if ($solved !== null) {
            $outcome->fact('Given on', $solved['label'].': '.$solved['currency'].' '.number_format($solved['target'], 2).($solved['basis'] === 'ctc' ? ' a year' : ' a month'),
                'The structure gives '.number_format($solved['achieved'], 2).($solved['difference'] != 0.0 ? ' ('.($solved['difference'] > 0 ? '+' : '').number_format($solved['difference'], 2).': the nearest its formulas come)' : '').'.');
        }

        if (($arrears = self::arrears($employee, $proposal, null)) !== null) {
            $outcome->fact('Arrears', $arrears['enabled']
                ? 'Paid in '.$arrears['payout'].' payroll for '.($arrears['count'] === 1 ? $arrears['first'] : $arrears['first'].' to '.$arrears['last'])
                : 'Not paid: payroll is finalised through '.$arrears['through_label'].' and arrears of back-dated changes are switched off, so execution will refuse this date',
                $arrears['enabled'] ? 'Each closed month is worked out again on the new salary when the change takes effect; the difference of each pay item is paid (or recovered).' : null);
        }

        return $outcome
            ->fact('Statutory details', ($state['change_statutory'] ?? false) ? 'Changed with this proposal' : 'Unchanged',
                ($state['change_statutory'] ?? false) ? 'Audited, through the Employee 360 action.' : null)
            ->note($submit ? 'Submitted for review; then approval and execution by other people.' : 'Saved as a draft for you to submit.', 'next', 'Next:')
            ->note('The proposal joins the salary structure requests, and payroll uses it only once it is executed and in force.', 'money', 'Nothing is approved or paid here:');
    }

    /**
     * Propose the change (with the statutory details first, when asked): one compensation change, submitted for review by
     * default. Refusals come back as a notification with the reason.
     *
     * @param  array<string, mixed>  $data
     */
    public static function submit(Employee $record, array $data, string $purpose = 'assign'): ?CompensationChange
    {
        $user = auth()->user();
        $data = self::forPurpose($record, $data, $purpose);
        try {
            $change = DB::transaction(function () use ($record, $data, $user) {
                if ((bool) ($data['change_statutory'] ?? false)) {
                    if (! $user->can('updateSensitive', $record)) {
                        throw new RuntimeException('Changing statutory details needs employee.sensitive.update.');
                    }
                    app(ChangeStatutoryApplicabilityAction::class)->handle($record, array_intersect_key($data, array_flip(ChangeStatutoryApplicabilityAction::FIELDS)), $user, (string) ($data['statutory_reason'] ?? ''));
                }
                $resolved = app(SalaryRevisions::class)->resolve($record, $data);

                return app(SalaryAssignments::class)->propose($record, $resolved['input'], $user, (bool) ($data['submit'] ?? true));
            });
            Notification::make()->success()
                ->title($change->status === 'submitted' ? 'Salary proposed and submitted for review' : 'Salary proposal saved as a draft')
                ->body('Reference '.$change->reference.'. A reviewer, an approver and an executor still decide it.')
                ->actions(CompensationChangeResource::canAccess() ? [Action::make('requests')->label('Salary structure requests')->url(CompensationChangeResource::getUrl('index'))] : [])
                ->send();

            return $change;
        } catch (RuntimeException|AuthorizationException|ValidationException $e) {
            Notification::make()->danger()->title('Salary not proposed')->body($e->getMessage())->persistent()->send();

            return null;
        }
    }

    /**
     * An edit is a correction on the date of the salary in force, whatever the form says (the fields are read-only in the
     * browser; this is the server's word on it).
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function forPurpose(Employee $employee, array $state, string $purpose): array
    {
        if ($purpose !== 'correct') {
            return $state;
        }
        $base = self::base($employee);

        return ['change_type' => 'correction', 'effective_from' => $base?->effectiveFrom->toDateString()] + $state;
    }

    /** The open request of an employee (draft, submitted or under review), for the banners that say a change is waiting. */
    public static function openRequest(Employee $employee): ?CompensationChange
    {
        return CompensationChange::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)
            ->whereIn('status', [...SalaryAssignments::OPEN, ...SalaryAssignments::DECIDED])->orderByDesc('id')->first();
    }
}
