<?php

namespace App\Filament\Resources\VariablePayPlans;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Payroll\Models\VariablePayPlan;
use App\Domain\Payroll\Services\VariablePayPlans;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\VariablePayPlans\Pages\ListVariablePayPlans;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use UnitEnum;

/**
 * HROne setup parity BP-9 (variable payment schedule): payroll plans an employee's variable pay by month; the manager
 * (or payroll) enters each month's payout, which becomes a variable pay request. Managers see their direct reports'
 * plans; employees see their own when `compensation.self_service.show_variable_plan` is on.
 */
class VariablePayPlanResource extends Resource
{
    protected static ?string $model = VariablePayPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Variable pay plans';

    protected static ?string $modelLabel = 'variable pay plan';

    protected static ?int $navigationSort = 72;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return auth()->user()?->can('payroll.manage') ? 'Payroll' : 'Me';
    }

    /** Payroll sees every plan in scope; others their direct reports' plans, and their own when the organisation shows them. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'component', 'requests']);
        $user = auth()->user();
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->can('payroll.manage')) {
            return $query;
        }
        $mine = Employee::query()->where('user_id', $user->id)->pluck('id');
        $reports = ReportingRelationship::query()->whereIn('manager_id', $mine)->where('is_primary', true)->effectiveOn()->pluck('employee_id');
        $own = (bool) app(SettingsRepository::class)->get('compensation.self_service.show_variable_plan', false) ? $mine : collect();

        return $query->whereIn('employee_id', $reports->merge($own)->all());
    }

    /** @return list<mixed> */
    public static function scheduleField(): array
    {
        return [Repeater::make('schedule')->label('Months and targets')->required()->minItems(1)->columns(2)->schema([
            TextInput::make('month')->label('Month (YYYY-MM)')->required()->regex('/^\d{4}-(0[1-9]|1[0-2])$/'),
            TextInput::make('target')->numeric()->minValue(0)->required(),
        ])];
    }

    public static function table(Table $table): Table
    {
        $run = fn (Closure $call, string $done) => function (VariablePayPlan $record, array $data) use ($call, $done) {
            try {
                $call($record, $data);
                Notification::make()->success()->title($done)->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not done')->body($e->getMessage())->persistent()->send();
            }
        };
        $plans = fn () => app(VariablePayPlans::class);
        $open = fn (VariablePayPlan $plan) => collect($plan->targets())->reject(fn ($target, string $month) => $plan->requests->where('plan_month', $month)->whereIn('status', ['pending', 'approved', 'paid'])->isNotEmpty());

        return $table
            ->columns([
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(['people.first_name', 'people.last_name'])->description(fn (VariablePayPlan $r) => $r->employee?->employee_code),
                TextColumn::make('name')->label('Plan')->state(fn (VariablePayPlan $r) => $r->name ?: $r->component?->name),
                TextColumn::make('component.name')->label('Pay item')->badge(),
                TextColumn::make('months')->label('Schedule')->state(fn (VariablePayPlan $r) => collect($r->targets())->map(fn ($t, $m) => $m.': '.number_format($t, 2))->implode(' · '))->wrap(),
                TextColumn::make('entered')->label('Payouts entered')->state(fn (VariablePayPlan $r) => $r->requests->whereIn('status', ['pending', 'approved', 'paid'])->count().' of '.count($r->targets())),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('status')->options(VariablePayPlan::STATUSES)->default('active')])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('payout')->label('Enter payout')->icon('heroicon-m-banknotes')->color('success')
                    ->visible(fn (VariablePayPlan $r) => $r->status === 'active' && $open($r)->isNotEmpty() && $plans()->mayEnter(auth()->user(), $r->employee))
                    ->schema(fn (VariablePayPlan $r) => [
                        Select::make('month')->options($open($r)->map(fn ($t, $m) => $m.' (target '.number_format($t, 2).')')->all())->required()->live()->native(false),
                        TextInput::make('amount')->numeric()->minValue(1)->required()->default(fn (Get $get) => $r->targets()[$get('month')] ?? null),
                        Textarea::make('note')->label('What it is for')->required()->maxLength(500),
                    ])
                    ->action($run(fn ($r, $d) => $plans()->enterPayout($r, $d['month'], (float) $d['amount'], $d['note'], auth()->user()), 'Payout sent for approval')),
                Action::make('editSchedule')->label('Change schedule')->icon('heroicon-m-pencil-square')
                    ->visible(fn (VariablePayPlan $r) => $r->status === 'active' && auth()->user()->can('update', $r))
                    ->fillForm(fn (VariablePayPlan $r) => ['name' => $r->name, 'schedule' => array_values((array) $r->schedule)])
                    ->schema([TextInput::make('name')->maxLength(120), ...self::scheduleField(), Textarea::make('reason')->required()->maxLength(500)])
                    ->action($run(fn ($r, $d) => $plans()->update($r, array_values((array) $d['schedule']), $d['name'] ?? null, auth()->user(), $d['reason']), 'Schedule saved')),
                Action::make('close')->icon('heroicon-m-archive-box')->color('gray')
                    ->visible(fn (VariablePayPlan $r) => $r->status === 'active' && auth()->user()->can('update', $r))
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action($run(fn ($r, $d) => $plans()->close($r, $d['reason'], auth()->user()), 'Plan closed')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListVariablePayPlans::route('/')];
    }
}
