<?php

namespace App\Filament\Resources\TaxDeclarations;

use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Filament\Pages\DeclareInvestments;
use App\Filament\Resources\TaxDeclarations\Pages\ManageTaxDeclarations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Regime choice and investment declarations (§32 TDS). Employees file their own in DeclareInvestments; payroll verifies. */
class TaxDeclarationResource extends Resource
{
    protected static ?string $model = EmployeeTaxDeclaration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Tax declarations';

    protected static ?int $navigationSort = 35;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person']);

        if (! auth()->user()->can('payroll.view')) {
            $query->whereHas('employee', fn (Builder $q) => $q->where('user_id', auth()->id()));
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        $canDeclare = DeclareInvestments::canAccess();

        return $table
            ->columns([
                TextColumn::make('financial_year')->label('FY')->sortable(),
                TextColumn::make('employee.employee_code')->label('Code')->searchable(),
                TextColumn::make('employee.person.full_name')->label('Employee'),
                TextColumn::make('regime')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.compliance.tax_regimes.{$state}", $state)),
                TextColumn::make('declared')->label('Declared total')->state(fn (EmployeeTaxDeclaration $record) => array_sum(array_map('floatval', collect($record->declarations ?? [])->except(['METRO'])->all())))->numeric(2),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'verified' => 'success', 'submitted' => 'info', default => 'gray'
                }),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(EmployeeTaxDeclaration::STATUSES)])
            ->recordActions([
                // The employee's items are filed in the guided declaration, which recalculates the section totals.
                Action::make('open')->label(fn (EmployeeTaxDeclaration $record) => auth()->user()->can('update', $record) ? 'Open' : 'View')->icon('heroicon-m-arrow-top-right-on-square')
                    ->visible(fn (EmployeeTaxDeclaration $record) => $canDeclare && (auth()->user()->hasPermission('payroll.manage') || (int) $record->employee?->user_id === (int) auth()->id()))
                    ->url(fn (EmployeeTaxDeclaration $record) => DeclareInvestments::getUrl(['employee' => $record->employee_id, 'year' => $record->financial_year])),
                Action::make('payrollFields')->label('Employer NPS')->icon('heroicon-m-pencil-square')->color('gray')
                    ->visible(fn () => auth()->user()->can('payroll.manage'))
                    ->modalHeading('Employer NPS contribution')
                    ->fillForm(fn (EmployeeTaxDeclaration $record) => ['employer_nps' => $record->amount('80CCD2') ?: null])
                    ->schema([
                        TextInput::make('employer_nps')->label('Employer’s NPS contribution for the year (80CCD(2))')->prefix('₹')->numeric()->minValue(0)->maxValue(1_000_000_000)
                            ->helperText('Counts in both regimes. The employee files everything else in the guided declaration.'),
                    ])
                    ->action(function (EmployeeTaxDeclaration $record, array $data) {
                        $amount = round((float) ($data['employer_nps'] ?? 0), 2);
                        $declarations = collect($record->declarations ?? [])->except('80CCD2')->all() + ($amount > 0 ? ['80CCD2' => $amount] : []);
                        $record->withAuditReason('Employer NPS contribution set by payroll')->update(['declarations' => $declarations]);
                        Notification::make()->success()->title('Employer NPS saved')->send();
                    }),
                Action::make('verify')->label('Verify')->icon('heroicon-m-check-badge')->color('success')
                    ->visible(fn (EmployeeTaxDeclaration $record) => $record->status !== 'verified' && auth()->user()->can('payroll.manage'))
                    ->requiresConfirmation()
                    ->action(function (EmployeeTaxDeclaration $record) {
                        $record->withAuditReason('Proofs verified')->update(['status' => 'verified', 'verified_by' => auth()->id(), 'verified_at' => now()]);
                        Notification::make()->success()->title('Declaration verified')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTaxDeclarations::route('/')];
    }
}
