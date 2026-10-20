<?php

namespace App\Filament\Resources\WorkforceBudgets;

use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Workforce\Models\WorkforceBudget;
use App\Domain\Workforce\Models\WorkforcePlanVersion;
use App\Domain\Workforce\Services\WorkforceBudgets;
use App\Filament\Resources\WorkforceBudgets\Pages\ManageWorkforceBudgets;
use App\Filament\Support\TalentActions;
use App\Filament\Support\WorkforceActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Phase 10 workforce budgets (workforce.costs): planned vs budget vs actual on one declared basis; no accounting, no payroll write. */
class WorkforceBudgetResource extends Resource
{
    protected static ?string $model = WorkforceBudget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Workforce';

    protected static ?string $navigationLabel = 'Budgets';

    protected static ?int $navigationSort = 60;

    /** @return array<int, Action> */
    public static function headerActions(): array
    {
        return [
            Action::make('create')->label('New budget')->icon(Heroicon::OutlinedPlus)->visible(fn () => auth()->user()->can('create', WorkforceBudget::class))
                ->schema([
                    TextInput::make('name')->required(),
                    Select::make('company_id')->label('Company')->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('organisation_node_id')->label('Organisation unit')->options(fn () => TalentActions::nodeOptions())->searchable(),
                    Select::make('cost_centre_id')->label('Cost centre')->options(fn () => CostCentre::query()->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('workforce_plan_version_id')->label('Plan version (for planned cost)')->options(fn () => WorkforcePlanVersion::query()->with('plan')->whereHas('plan')->latest('id')->limit(200)->get()->mapWithKeys(fn ($v) => [$v->id => $v->plan?->code.' v'.$v->version])->all()),
                    DatePicker::make('period_start')->native(false)->required(),
                    DatePicker::make('period_end')->native(false)->required()->afterOrEqual('period_start'),
                    Select::make('cost_basis')->options(config('peopleos.workforce.cost_bases'))->required()->helperText('Actual payroll cost is compared only with an employer-cost budget.'),
                    TextInput::make('amount')->numeric()->minValue(0)->required(),
                    TextInput::make('currency')->maxLength(3)->helperText('Defaults to the company currency.'),
                    Textarea::make('notes')->rows(2),
                ])
                ->action(fn (array $data) => WorkforceActions::run(fn () => app(WorkforceBudgets::class)->create(WorkforceActions::filled($data), auth()->user()), 'Budget recorded as a draft')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('period')->state(fn (WorkforceBudget $record) => $record->period_start->toDateString().' – '.$record->period_end->toDateString()),
                TextColumn::make('cost_basis')->label('Basis')->formatStateUsing(fn (string $state) => config("peopleos.workforce.cost_bases.{$state}", $state)),
                TextColumn::make('amount')->numeric(2)->suffix(fn (WorkforceBudget $record) => ' '.$record->currency),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'approved' => 'success', 'superseded' => 'gray', default => 'info'
                }),
            ])
            ->defaultSort('period_start', 'desc')
            ->recordActions([
                Action::make('compare')->label('Planned vs actual')->icon(Heroicon::OutlinedScale)->color('gray')->modalSubmitAction(false)
                    ->schema(fn (WorkforceBudget $record) => collect(app(WorkforceBudgets::class)->comparison($record, auth()->user()))->except(['currency', 'cost_basis'])
                        ->map(fn ($value, $key) => TextEntry::make("c_{$key}")->label(ucfirst(str_replace('_', ' ', $key)))->state($value === null ? '—' : (is_numeric($value) ? number_format((float) $value, 2).' '.$record->currency : $value)))->values()->all()),
                Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheck)->color('success')->requiresConfirmation()
                    ->visible(fn (WorkforceBudget $record) => $record->status === 'draft' && auth()->user()->can('workforce.approve') && (int) $record->created_by !== (int) auth()->id())
                    ->action(fn (WorkforceBudget $record) => WorkforceActions::run(fn () => app(WorkforceBudgets::class)->approve($record, auth()->user()), 'Budget approved')),
                Action::make('supersede')->label('Supersede')->icon(Heroicon::OutlinedArchiveBox)->color('danger')
                    ->visible(fn (WorkforceBudget $record) => $record->status === 'approved' && auth()->user()->can('workforce.approve'))
                    ->schema([Textarea::make('reason')->required()])
                    ->action(fn (WorkforceBudget $record, array $data) => WorkforceActions::run(fn () => app(WorkforceBudgets::class)->supersede($record, $data['reason'], auth()->user()), 'Budget superseded')),
            ])
            ->emptyStateHeading('No workforce budgets');
    }

    public static function getPages(): array
    {
        return ['index' => ManageWorkforceBudgets::route('/')];
    }
}
