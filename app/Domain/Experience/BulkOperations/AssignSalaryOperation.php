<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\SalaryAssignments;
use App\Domain\Compensation\Support\CompensationSnapshot;
use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Identity\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * HROne parity PO-2 (matrix 25 §25.2.2 #30, "Assign salary" for the employees selected on the salary structure register):
 * one compensation proposal per employee through SalaryAssignments::propose → CompensationChanges (submitted for review by
 * default), so review, approval and execution by other people apply as for any proposal. Refused per employee: outside the
 * access scope, one's own compensation, a change already pending, no structure to keep, no CTC to revise.
 */
final class AssignSalaryOperation extends BaseBulkOperation
{
    public const KEY = 'salary_structures.assign';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Assign salary';
    }

    public function description(): string
    {
        return 'Proposes the salary for each selected employee (one compensation change each, submitted for review); nothing is approved or applied here. Employees with a change already pending, your own record and employees outside your access are refused.';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function permission(): string
    {
        return 'compensation.propose';
    }

    public function icon(): string
    {
        return 'heroicon-m-banknotes';
    }

    /**
     * The proposal fields, shared by the one-employee wizard and the bulk form.
     *
     * @return list<mixed>
     */
    public static function fields(?CompensationSnapshot $current = null, bool $many = false): array
    {
        $existing = $many || $current !== null;
        $modes = $many
            ? ['percent' => 'Revise by % of each one\'s CTC in force', 'amount' => 'The same annual CTC for each', 'keep' => 'Keep each one\'s CTC (structure change only)']
            : ($current !== null ? SalaryAssignments::CTC_MODES : ['amount' => SalaryAssignments::CTC_MODES['amount']]);
        $currencies = (array) config('peopleos.compensation.currencies', []);
        $base = app(CurrencyRates::class)->baseCurrency();

        return [
            Grid::make(['default' => 1, 'md' => 2])->schema([
                Select::make('change_type')->label('Change type')->options((array) config('peopleos.compensation.change_types'))->required()
                    ->default($existing ? 'revision' : 'hire'),
                DatePicker::make('effective_from')->label('Salary effective from')->native(false)->required()
                    ->default(now()->startOfMonth()->addMonth()->toDateString()),
                Select::make('salary_structure_id')->label('Salary structure (pay group)')->required()->live()
                    ->options(fn () => ($existing ? ['keep' => $many ? 'Keep each one\'s structure in force' : 'Keep the structure in force ('.$current->structureCode.')'] : [])
                        + SalaryStructure::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                    ->default($existing ? 'keep' : null),
                Radio::make('ctc_mode')->label('New CTC')->options($modes)->required()->live()
                    ->default($many ? 'percent' : 'amount'),
                TextInput::make('ctc_annual')->label($many ? 'Annual CTC (for each)' : 'Annual CTC')->numeric()->minValue(1)->maxValue(999999999999)
                    ->visible(fn (Get $get) => $get('ctc_mode') === 'amount')
                    ->helperText('A structure built from component amounts works its CTC out from them instead.')
                    ->default($current?->ctcAnnual),
                TextInput::make('increase_percent')->label('Increase (%)')->numeric()->minValue(-99.99)->maxValue(1000)->suffix('%')->live(onBlur: true)
                    ->visible(fn (Get $get) => $get('ctc_mode') === 'percent')->required(fn (Get $get) => $get('ctc_mode') === 'percent')
                    ->helperText(fn (Get $get) => $current !== null && is_numeric($get('increase_percent'))
                        ? 'New annual CTC: '.$current->currency.' '.number_format(SalaryAssignments::revise($current->ctcAnnual, (float) $get('increase_percent')), 2).' (from '.number_format($current->ctcAnnual, 2).', rounded to two decimals)'
                        : 'Worked out from the CTC in force on the effective date, rounded to two decimals.'),
                Select::make('currency')->options(array_combine($currencies, $currencies) ?: [])->required(fn (Get $get) => $get('ctc_mode') === 'amount')
                    ->visible(fn (Get $get) => $get('ctc_mode') === 'amount')->default($current?->currency ?? $base),
                ...($many ? [] : [
                    TextInput::make('variable_target_annual')->label('Variable target (annual)')->numeric()->minValue(0)->default($current?->variableTargetAnnual),
                ]),
            ]),
            ...($many ? [] : [
                KeyValue::make('component_values')->label('Fixed component amounts and inputs (monthly)')->keyLabel('Component code')->valueLabel('Amount')
                    ->helperText('For components calculated as "fixed" or "input", e.g. CONV = 1600. Prefilled from the compensation in force.')
                    ->default($current?->componentValues ?? []),
            ]),
            Textarea::make('reason')->label('Reason (shown with the change)')->required()->maxLength(2000),
            Textarea::make('internal_notes')->label('Internal notes (never shown to the employee)')->maxLength(2000),
            Toggle::make('submit')->label('Submit for review now')->default(true)
                ->helperText('Off: saved as a draft you submit later. Either way a reviewer, an approver and an executor (other people) decide it.'),
        ];
    }

    /**
     * SalaryAssignments' input from the form (the keys it reads; component amounts and variable target only when the form
     * asked for them).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function input(array $data): array
    {
        $keys = ['change_type', 'effective_from', 'salary_structure_id', 'ctc_mode', 'ctc_annual', 'increase_percent', 'currency', 'reason', 'internal_notes', 'submit'];
        if (array_key_exists('variable_target_annual', $data)) {
            $keys[] = 'variable_target_annual';
            $data['variable_target_annual'] = filled($data['variable_target_annual']) ? $data['variable_target_annual'] : null;
        }
        if (array_key_exists('component_values', $data)) {
            $keys[] = 'component_values';
            $data['component_values'] = array_filter((array) ($data['component_values'] ?? []), fn ($v, $k) => filled($k) && filled($v), ARRAY_FILTER_USE_BOTH);
        }

        return array_intersect_key($data, array_flip($keys));
    }

    public function schema(): array
    {
        return self::fields(many: true);
    }

    public function prepare(array $data, User $actor): array
    {
        $type = (string) ($data['change_type'] ?? '');
        if (! array_key_exists($type, (array) config('peopleos.compensation.change_types', []))) {
            throw new RuntimeException('Choose the change type.');
        }
        try {
            $from = Carbon::parse((string) ($data['effective_from'] ?? ''))->toDateString();
        } catch (Throwable) {
            throw new RuntimeException('Give the date the salary takes effect.');
        }
        if (blank($data['effective_from'] ?? null)) {
            throw new RuntimeException('Give the date the salary takes effect.');
        }
        $structure = $data['salary_structure_id'] ?? 'keep';
        if ($structure !== 'keep' && ! SalaryStructure::query()->whereKey((int) $structure)->where('status', 'active')->exists()) {
            throw new RuntimeException('Choose an active salary structure, or keep each one\'s structure.');
        }
        $mode = (string) ($data['ctc_mode'] ?? '');
        if (! array_key_exists($mode, SalaryAssignments::CTC_MODES)) {
            throw new RuntimeException('Choose how the new CTC is given.');
        }
        if ($mode === 'percent' && (! is_numeric($data['increase_percent'] ?? null) || (float) $data['increase_percent'] <= -100 || (float) $data['increase_percent'] > 1000)) {
            throw new RuntimeException('The increase must be a percentage above -100 and at most 1000.');
        }
        if ($mode === 'amount' && (! is_numeric($data['ctc_annual'] ?? null) || (float) $data['ctc_annual'] <= 0)) {
            throw new RuntimeException('Give the annual CTC.');
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Give the reason for the change.');
        }

        return [
            'change_type' => $type, 'effective_from' => $from, 'salary_structure_id' => $structure === 'keep' ? 'keep' : (int) $structure, 'ctc_mode' => $mode,
            'ctc_annual' => $mode === 'amount' ? round((float) $data['ctc_annual'], 2) : null,
            'increase_percent' => $mode === 'percent' ? round((float) $data['increase_percent'], 2) : null,
            'currency' => $mode === 'amount' && filled($data['currency'] ?? null) ? strtoupper((string) $data['currency']) : null,
            'reason' => mb_substr($reason, 0, 2000), 'internal_notes' => filled($data['internal_notes'] ?? null) ? mb_substr((string) $data['internal_notes'], 0, 2000) : null,
            'submit' => (bool) ($data['submit'] ?? true),
        ];
    }

    public function with(): array
    {
        return ['person'];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        /** @var Employee $record */
        return app(SalaryAssignments::class)->refusal($record, $actor);
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Employee $record */
        $change = app(SalaryAssignments::class)->propose($record, $input, $actor, (bool) ($input['submit'] ?? true));

        // No amounts: run results are read by whoever may see the run, not only by compensation readers.
        return $change->reference.': '.config('peopleos.compensation.change_types.'.$change->change_type, $change->change_type).' from '.$change->effective_from->format('j M Y')
            .' — '.($change->status === 'submitted' ? 'submitted for review' : 'saved as a draft');
    }
}
