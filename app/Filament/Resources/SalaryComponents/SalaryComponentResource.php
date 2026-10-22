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
                Select::make('calculation_method')->label('Calculated')->options(collect(config('peopleos.payroll.calculation_methods'))->except('statutory')->all())->default('fixed')->required()->live()->disabled($locked)->dehydrated(),
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
                Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
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
            ->filters([SelectFilter::make('type')->options(config('peopleos.payroll.component_types'))])
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
