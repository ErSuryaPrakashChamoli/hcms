<?php

namespace App\Filament\Resources\TaxDeclarations;

use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\TaxDeclarations\Pages\ManageTaxDeclarations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Regime choice and investment declarations (§32 TDS). Employees file their own; payroll verifies. */
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

    public static function form(Schema $schema): Schema
    {
        $manager = auth()->user()->can('payroll.manage');
        $fy = app(FinancialYear::class);

        return $schema->components([
            Section::make('Declaration')->columns(3)->schema([
                Select::make('employee_id')->label('Employee')->required()->searchable()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated()
                    ->options(fn () => ($manager ? Employee::query()->with('person')->employed() : Employee::query()->with('person')->where('user_id', auth()->id()))->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all())
                    ->default(fn () => $manager ? null : Employee::query()->where('user_id', auth()->id())->value('id')),
                Select::make('financial_year')->options([$fy->label(now()->subYear()) => $fy->label(now()->subYear()), $fy->label(now()) => $fy->label(now()), $fy->label(now()->addYear()) => $fy->label(now()->addYear())])->default($fy->label(now()))->required(),
                Select::make('regime')->options(config('peopleos.compliance.tax_regimes'))->default('new')->required()->helperText('Chapter VI-A and HRA exemption apply in the old regime only'),
                TextInput::make('previous_employer_income')->numeric()->default(0)->label('Previous employer taxable income'),
                TextInput::make('previous_employer_tds')->numeric()->default(0)->label('TDS deducted by previous employer'),
                Select::make('status')->options(EmployeeTaxDeclaration::STATUSES)->default('draft')->required()->visible($manager),
            ]),
            Section::make('Declared amounts (annual)')->schema([
                KeyValue::make('declarations')->hiddenLabel()->keyLabel('Section')->valueLabel('Amount')->addActionLabel('Add section')
                    ->helperText(collect(EmployeeTaxDeclaration::SECTIONS)->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · ')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
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
                EditAction::make(),
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
