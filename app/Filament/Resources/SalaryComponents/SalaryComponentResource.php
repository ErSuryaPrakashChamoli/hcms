<?php

namespace App\Filament\Resources\SalaryComponents;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\FormulaEngine;
use App\Domain\Payroll\Support\FormulaVariables;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\SalaryComponents\Pages\CreateSalaryComponent;
use App\Filament\Resources\SalaryComponents\Pages\EditSalaryComponent;
use App\Filament\Resources\SalaryComponents\Pages\ListSalaryComponents;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\SalaryFormulaUi;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use RuntimeException;
use UnitEnum;

/** Salary components (§30). Statutory lines are produced by the compliance engine, not configured here. */
class SalaryComponentResource extends Resource
{
    protected static ?string $model = SalaryComponent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Salary components';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        $locked = fn (?SalaryComponent $record) => $record?->is_statutory ?? false;

        return $schema->components([
            Section::make('Component')->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated()
                    ->helperText('Referenced in formulas as its lowercase name, e.g. basic')
                    ->rule(fn () => fn (string $attribute, $value, Closure $fail) => match (true) {
                        in_array(strtoupper($value), SalaryComponent::STATUTORY_CODES, true) => $fail('This code is reserved for a statutory line.'),
                        in_array(strtolower($value), FormulaVariables::reserved(), true) => $fail('This code is a formula variable or function name; choose another.'),
                        default => null,
                    }),
                Select::make('type')->options(config('peopleos.payroll.component_types'))->required()->disabled($locked)->dehydrated(),
                Select::make('classification')->options(config('peopleos.payroll.classifications'))->required()->default('allowance')->disabled($locked)->dehydrated(),
                Select::make('calculation_method')->label('Calculated')->options(collect(config('peopleos.payroll.calculation_methods'))->except('statutory')->all())->default('fixed')->required()->live()->disabled($locked)->dehydrated()
                    // HROne setup parity BP-2 (CTC formula tags).
                    ->helperText(fn (Get $get) => $get('calculation_method') === 'input' ? 'A value set on each employee\'s compensation (e.g. a rate or a target) that formulas read by the lowercase code. It is never paid or printed; its name is what people see.' : null),
                TextInput::make('sort_order')->numeric()->default(0)->helperText('Default position in new structures; in a structure, a formula uses only the rows above it'),
                Textarea::make('formula')->rows(2)->columnSpanFull()
                    ->visible(fn (Get $get) => $get('calculation_method') === 'formula')
                    ->required(fn (Get $get) => $get('calculation_method') === 'formula')
                    ->hintAction(SalaryFormulaUi::guideAction())
                    ->helperText('The default formula for every structure; a structure can override it. E.g. ctc_monthly * 0.50, basic * 0.40, lookup(grade, "G1", 1600, 2400). Each structure checks the names it uses when its draft is saved.')
                    ->rule(fn () => function (string $attribute, $value, Closure $fail) {
                        try {
                            app(FormulaEngine::class)->validate((string) $value);
                            $statutory = array_intersect(app(FormulaEngine::class)->variablesIn((string) $value), FormulaVariables::statutory());
                            if ($statutory !== []) {
                                $fail('Statutory amounts ('.strtoupper(implode(', ', $statutory)).') are worked out by the compliance engine and cannot be used in formulas.');
                            }
                        } catch (RuntimeException $e) {
                            $fail($e->getMessage());
                        }
                    }),
            ]),
            Section::make('Properties')->columns(4)->schema([
                Toggle::make('taxable')->default(true),
                Toggle::make('pf_applicable')->label('PF wages'),
                Toggle::make('esi_applicable')->label('ESI wages')->default(true),
                Toggle::make('include_in_ctc')->label('Part of CTC')->default(true),
                Toggle::make('include_in_gross')->label('Part of gross')->default(true),
                Toggle::make('is_recurring')->label('Recurring')->default(true)->helperText('Off = paid only through adjustments'),
                Toggle::make('is_proratable')->label('Prorated for LOP')->default(true),
                Toggle::make('is_arrear_eligible')->label('Arrear eligible')->default(true),
                // HROne parity PY-02: a reimbursement paid against claims (with bills) instead of every month.
                Toggle::make('pay_on_claim')->label('Paid on claim')->helperText('Reimbursements only: the monthly amount builds up a balance employees claim against with bills.')
                    ->visible(fn ($get) => $get('type') === 'reimbursement'),
                // HROne setup parity 08.6 (Workforce → Transfer allowances): offered on transfer requests, paid once by payroll.
                Toggle::make('transfer_allowance')->label('Transfer allowance')->helperText('Offered on transfer requests (the name is the label) and paid once with payroll, with this component\'s tax, PF and ESI treatment.')
                    ->visible(fn ($get) => in_array($get('type'), ['earning', 'reimbursement'], true)),
                Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            ]),
            // HROne setup parity (PS-1): statutory wages, display, joining / exit months, rounding, tax by regime, claims.
            Section::make('Statutory wages, display and processing')->columns(4)->collapsible()->schema([
                Select::make('pt_wages')->label('Professional tax wages')->options([1 => 'Yes', 0 => 'No'])->placeholder('As part of gross'),
                Select::make('lwf_wages')->label('LWF wages')->options([1 => 'Yes', 0 => 'No'])->placeholder('As part of gross'),
                Select::make('esi_eligibility_wages')->label('Counts for the ESI ceiling')->options([1 => 'Yes', 0 => 'No'])->placeholder('As ESI wages'),
                Select::make('pay_nature')->label('Nature of payment')->options(['fixed' => 'Fixed', 'variable' => 'Variable'])->default('fixed')->required(),
                Toggle::make('show_on_payslip')->label('Show on the payslip')->default(true),
                Toggle::make('show_in_register')->label('Show on the salary register')->default(true),
                Toggle::make('pay_in_joining_month')->label('Pay in the joining month')->default(true),
                Toggle::make('pay_in_exit_month')->label('Pay in the exit month')->default(true),
                Select::make('rounding')->options(['nearest_1' => 'Nearest rupee', 'up_1' => 'Up to the rupee', 'nearest_10' => 'Nearest ten'])->placeholder('No rounding'),
                Select::make('exempt_in_regimes')->label('Tax-free in (non-taxable components)')->multiple()->options(config('peopleos.compliance.tax_regimes'))->placeholder('Every regime'),
                Select::make('claim_attachment_required')->label('Claims need bills')->options([1 => 'Yes', 0 => 'No'])->placeholder('Organisation setting')
                    ->visible(fn ($get) => $get('type') === 'reimbursement'),
                TextInput::make('claim_window_days')->label('Bills no older than (days)')->numeric()->minValue(1)->visible(fn ($get) => $get('type') === 'reimbursement'),
                TextInput::make('claim_limit_per_claim')->label('Most per claim')->numeric()->minValue(0)->visible(fn ($get) => $get('type') === 'reimbursement'),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('type')->badge()->color(fn (string $state) => match ($state) {
                    'earning' => 'success', 'deduction' => 'danger', 'employer_contribution' => 'info', default => 'gray'
                })->formatStateUsing(fn (string $state) => config("peopleos.payroll.component_types.{$state}", $state)),
                TextColumn::make('calculation_method')->label('Method')->badge()->color('gray'),
                TextColumn::make('formula')->limit(40)->placeholder('—')->toggleable(),
                IconColumn::make('taxable')->boolean(),
                IconColumn::make('pf_applicable')->label('PF')->boolean(),
                IconColumn::make('esi_applicable')->label('ESI')->boolean(),
                IconColumn::make('is_proratable')->label('Prorate')->boolean(),
                IconColumn::make('is_statutory')->label('Statutory')->boolean()->toggleable(),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('sort_order')
            ->filters([SelectFilter::make('type')->options(config('peopleos.payroll.component_types')), TernaryFilter::make('transfer_allowance')->label('Transfer allowances')])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalaryComponents::route('/'),
            'create' => CreateSalaryComponent::route('/create'),
            'edit' => EditSalaryComponent::route('/{record}/edit'),
        ];
    }
}
