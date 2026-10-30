<?php

namespace App\Filament\Resources\PfOptions;

use App\Domain\Compliance\Models\EmployeePfOption;
use App\Filament\Resources\PfOptions\Pages\ListPfOptions;
use App\Filament\Support\PayrollRegisters;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** HROne parity PY-15: voluntary PF and each employee's PF wage basis, effective-dated. */
class PfOptionResource extends Resource
{
    protected static ?string $model = EmployeePfOption::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'PF options (VPF)';

    protected static ?string $modelLabel = 'PF option';

    protected static ?string $slug = 'pf-options';

    protected static ?int $navigationSort = 76;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('payroll.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'creator']))
            ->columns([
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(['people.first_name', 'people.last_name'])->description(fn (EmployeePfOption $r) => $r->employee?->employee_code),
                TextColumn::make('effective_from')->label('From')->date()->sortable(),
                TextColumn::make('vpf_mode')->label('Voluntary PF')->state(fn (EmployeePfOption $r) => match ($r->vpf_mode) {
                    'percent' => rtrim(rtrim(number_format((float) $r->vpf_value, 2), '0'), '.').'% of PF wages',
                    'amount' => number_format((float) $r->vpf_value, 2).' a month',
                    default => 'None',
                }),
                TextColumn::make('wage_basis')->label('PF wages')->state(fn (EmployeePfOption $r) => EmployeePfOption::WAGE_BASES[$r->wage_basis] ?? 'Establishment rule'),
                TextColumn::make('reason')->wrap()->limit(60),
                TextColumn::make('creator.name')->label('Set by')->placeholder('—'),
            ])
            // HROne parity PO-1: the WO-2 employee filters.
            ->filters(PayrollRegisters::filters('payroll.manage'))
            ->defaultSort('effective_from', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListPfOptions::route('/')];
    }
}
