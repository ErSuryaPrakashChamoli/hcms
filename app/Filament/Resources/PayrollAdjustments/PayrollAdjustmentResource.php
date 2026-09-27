<?php

namespace App\Filament\Resources\PayrollAdjustments;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Filament\Resources\PayrollAdjustments\Pages\ManagePayrollAdjustments;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** One-off inputs for a period (§31): extra earnings, deductions, reimbursements, manual LOP. */
class PayrollAdjustmentResource extends Resource
{
    protected static ?string $model = PayrollAdjustment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Adjustments';

    protected static ?int $navigationSort = 20;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee.person', 'period', 'component']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('employee_id')->label('Employee')->required()->searchable()
                ->options(fn () => Employee::query()->with('person')->employed()->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all()),
            Select::make('payroll_period_id')->label('Period')->required()
                ->options(fn () => self::periodOptions())
                ->helperText('Only open periods are listed'),
            Select::make('type')->options(PayrollAdjustment::TYPES)->required()->live(),
            Select::make('salary_component_id')->label('Component')->placeholder('Ad hoc')
                ->options(fn (Get $get) => SalaryComponent::query()->where('status', 'active')->where('is_statutory', false)->where('type', $get('type') === 'deduction' ? 'deduction' : ($get('type') === 'reimbursement' ? 'reimbursement' : 'earning'))->orderBy('sort_order')->pluck('name', 'id')->all())
                ->visible(fn (Get $get) => $get('type') !== 'lop'),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('amount')->numeric()->required()->minValue(0)->label(fn (Get $get) => $get('type') === 'lop' ? 'Days' : 'Amount'),
            Toggle::make('taxable')->default(true)->visible(fn (Get $get) => $get('type') === 'earning'),
            TextInput::make('note')->maxLength(255)->columnSpanFull(),
        ]);
    }

    /** @return array<int, string> */
    public static function periodOptions(): array
    {
        $options = [];
        foreach (Company::query()->orderBy('name')->get() as $company) {
            foreach ([now()->subMonth(), now(), now()->addMonth()] as $d) {
                $period = PayrollPeriod::for($company, $d->year, $d->month);
                if ($period->status === 'open') {
                    $options[$period->id] = "{$company->name} · {$period->label()}";
                }
            }
        }

        return $options;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('period.start_date')->label('Period')->formatStateUsing(fn ($state, PayrollAdjustment $record) => $record->period->label())->sortable(),
                TextColumn::make('employee.employee_code')->label('Code')->searchable(),
                TextColumn::make('employee.person.full_name')->label('Employee'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'approved' ? 'success' : ($state === 'pending' ? 'warning' : 'gray')),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => PayrollAdjustment::TYPES[$state] ?? $state)->color(fn (string $state) => match ($state) {
                    'deduction', 'lop' => 'danger', default => 'success'
                }),
                TextColumn::make('name'),
                TextColumn::make('amount')->numeric(2),
                TextColumn::make('note')->placeholder('—')->limit(40)->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('type')->options(PayrollAdjustment::TYPES)])
            ->recordActions([
                Action::make('approve')->label('Approve')->icon('heroicon-m-check')->color('success')
                    ->visible(fn (PayrollAdjustment $record) => $record->status === 'pending' && $record->created_by !== auth()->id() && (auth()->user()?->can('payroll.approve') ?? false))
                    ->requiresConfirmation()
                    ->action(function (PayrollAdjustment $record) {
                        $record->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
                        app(AuditRecorder::class)->record(AuditAction::PayrollAdjustmentApproved, 'payroll', $record, reason: $record->note);
                    }),
                EditAction::make()->visible(fn (PayrollAdjustment $record) => $record->period->status === 'open'),
                DeleteAction::make()->visible(fn (PayrollAdjustment $record) => $record->period->status === 'open'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePayrollAdjustments::route('/')];
    }
}
