<?php

namespace App\Filament\Support;

use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\StructureFormulas;
use App\Domain\Payroll\Support\FormulaVariables;
use App\Support\Tenancy\TenantContext;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * §30 formula help in the admin: the formula guide (generated from FormulaVariables, with a worked example
 * from StructureFormulas, so it never drifts from the engine; also downloadable) and the live breakup
 * preview of a structure for a sample CTC. Display only: drafts are still checked by
 * CompensationStructures and pay is still calculated by PayrollCalculator.
 */
final class SalaryFormulaUi
{
    public static function guideAction(string $name = 'formulaGuide'): Action
    {
        return Action::make($name)->label('Formula guide')->icon('heroicon-m-book-open')->color('gray')
            ->modalHeading('Salary formula guide')->modalWidth('5xl')->slideOver()
            ->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->modalContent(fn () => view('filament.payroll.formula-guide', self::guideData()))
            ->extraModalFooterActions([
                Action::make('downloadGuide')->label('Download')->icon('heroicon-m-arrow-down-tray')->color('gray')
                    ->action(fn () => response()->streamDownload(fn () => print (self::guideDocument()), 'salary-formula-guide.html', ['Content-Type' => 'text/html'])),
            ]);
    }

    /** The guide as a standalone HTML page to keep, share or print to PDF. */
    public static function guideDocument(): string
    {
        return view('filament.payroll.formula-guide-document', self::guideData() + ['tenant' => app(TenantContext::class)->current()?->name])->render();
    }

    /** @return array<string, mixed> */
    private static function guideData(): array
    {
        try {
            $example = app(StructureFormulas::class)->example();
        } catch (Throwable $e) {
            report($e);
            $example = null;
        }

        return [
            'pay' => FormulaVariables::PAY, 'employee' => FormulaVariables::EMPLOYEE, 'functions' => FormulaVariables::FUNCTIONS,
            'recipes' => FormulaVariables::RECIPES, 'statutory' => SalaryComponent::STATUTORY_CODES, 'example' => $example,
        ];
    }

    /**
     * Sample inputs and the live breakup. The closures read the structure being previewed (a saved
     * version, or the draft form being edited). Fields are never dehydrated, so nothing here is saved.
     *
     * @param  Closure(Get): list<array<string, mixed>>  $lines
     * @param  Closure(Get): array<string, float>  $parameters
     * @param  Closure(Get): mixed  $on  the date whose statutory rules the employer-PF estimate uses
     * @return list<mixed>
     */
    public static function preview(Closure $lines, Closure $parameters, Closure $on): array
    {
        return [
            Grid::make(['default' => 1, 'md' => 5])->schema([
                TextInput::make('preview_ctc')->label('Sample annual CTC')->numeric()->minValue(0)->default(600000)->live(debounce: 600)->dehydrated(false),
                TextInput::make('preview_variable')->label('Variable target (annual)')->numeric()->minValue(0)->live(debounce: 600)->dehydrated(false),
                TextInput::make('preview_days')->label('Days in the month')->numeric()->minValue(28)->maxValue(31)->default(30)->live(debounce: 600)->dehydrated(false),
                Toggle::make('preview_pf')->label('Covered by PF')->default(true)->live()->dehydrated(false)->inline(false),
                Toggle::make('preview_pf_ceiling')->label('PF on the wage ceiling only')->default(true)->live()->dehydrated(false)->inline(false),
            ])->columnSpanFull(),
            Grid::make(['default' => 1, 'md' => 2])->schema([
                KeyValue::make('preview_values')->label('Fixed amounts (monthly)')->keyLabel('Component code')->valueLabel('Amount')->addActionLabel('Add amount')
                    ->helperText('Fixed components take their amount from each employee’s compensation; try CONV = 1600.')->live(onBlur: true)->dehydrated(false),
                KeyValue::make('preview_attributes')->label('Employee values')->keyLabel('Name')->valueLabel('Value')->addActionLabel('Add value')
                    ->helperText('For formulas that use employee values; try grade = G2, work_city = Mumbai, years_of_service = 3.')->live(onBlur: true)->dehydrated(false),
            ])->columnSpanFull(),
            View::make('filament.payroll.structure-preview')->columnSpanFull()
                ->viewData(function (Get $get) use ($lines, $parameters, $on) {
                    try {
                        return ['result' => app(StructureFormulas::class)->preview($lines($get), $parameters($get), [
                            'ctc_annual' => $get('preview_ctc') ?? 600000, 'variable_target_annual' => $get('preview_variable'), 'days' => $get('preview_days') ?: 30,
                            'values' => (array) $get('preview_values'), 'attributes' => (array) $get('preview_attributes'),
                            // Today's statutory rules for a version already in force; its own date for a later one.
                            'pf_applies' => $get('preview_pf') ?? true, 'pf_restrict_to_ceiling' => $get('preview_pf_ceiling') ?? true, 'on' => Carbon::parse($on($get) ?? now())->max(now()),
                        ]), 'failure' => null];
                    } catch (Throwable $e) {
                        report($e);

                        return ['result' => null, 'failure' => 'The preview could not be worked out from these values.'];
                    }
                }),
        ];
    }

    /**
     * Lines from the draft form's repeater, in the order the draft would save them.
     *
     * @return list<array{component: SalaryComponent, formula_override: ?string, sort_order: int}>
     */
    public static function linesFromForm(mixed $rows): array
    {
        $rows = array_values(array_filter((array) $rows, fn ($row) => filled($row['salary_component_id'] ?? null)));
        $components = SalaryComponent::query()->whereIn('id', array_map(fn ($row) => (int) $row['salary_component_id'], $rows))->get()->keyBy('id');

        return collect($rows)
            ->map(fn (array $row, int $i) => ($component = $components->get((int) $row['salary_component_id']))
                ? ['component' => $component, 'formula_override' => $row['formula_override'] ?? null, 'sort_order' => (int) ($row['sort_order'] ?? ($i + 1) * 10)]
                : null)
            ->filter()->values()->all();
    }

    /** @return array<string, float> numeric parameters typed into the draft form */
    public static function parametersFromForm(mixed $rows): array
    {
        return collect((array) $rows)->filter(fn ($value, $name) => filled($name) && is_numeric($value))
            ->mapWithKeys(fn ($value, $name) => [strtolower(trim((string) $name)) => (float) $value])->all();
    }

    /** What a structure line's formula field shows when it has no override. */
    public static function defaultFormula(mixed $componentId): string
    {
        $component = filled($componentId) ? SalaryComponent::query()->find((int) $componentId) : null;

        return match (true) {
            $component === null => 'Optional: a formula for this structure only',
            $component->formulaFor(null) !== null => $component->formulaFor(null),
            default => 'Fixed amount from each employee’s compensation',
        };
    }
}
