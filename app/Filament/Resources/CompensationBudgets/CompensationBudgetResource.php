<?php

namespace App\Filament\Resources\CompensationBudgets;

use App\Domain\Compensation\Models\CompensationBudget;
use App\Domain\Compensation\Services\CompensationBudgets;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\Workforce\Models\WorkforceBudget;
use App\Filament\Resources\CompensationBudgets\Pages\ManageCompensationBudgets;
use App\Filament\Support\CompensationActions;
use App\Filament\Support\TalentActions;
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

/**
 * Phase 11 §16: compensation budgets on one declared basis (annualised CTC increase). Planned,
 * approved, committed, actual and variance come from the changes charged to the budget.
 */
class CompensationBudgetResource extends Resource
{
    protected static ?string $model = CompensationBudget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Compensation';

    protected static ?string $navigationLabel = 'Budgets';

    protected static ?int $navigationSort = 50;

    /** @return array<int, Action> */
    public static function headerActions(): array
    {
        return [
            Action::make('create')->label('New budget')->icon(Heroicon::OutlinedPlus)->visible(fn () => auth()->user()->can('compensation.budget'))
                ->schema([
                    TextInput::make('code')->required()->maxLength(40),
                    TextInput::make('name')->required(),
                    Select::make('company_id')->label('Company')->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all())->required(),
                    Select::make('organisation_node_id')->label('Organisation unit (optional)')->options(fn () => TalentActions::nodeOptions())->searchable(),
                    Select::make('location_id')->label('Location (optional)')->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')->all()),
                    DatePicker::make('period_start')->native(false)->required(),
                    DatePicker::make('period_end')->native(false)->required()->afterOrEqual('period_start'),
                    Select::make('currency')->options(fn () => array_combine(config('peopleos.compensation.currencies'), config('peopleos.compensation.currencies')))->default(config('peopleos.settings.tenant.base_currency', 'INR'))->required(),
                    TextInput::make('amount')->label('Amount (annualised CTC increase)')->numeric()->minValue(0)->required(),
                    Select::make('workforce_budget_id')->label('Workforce budget (reference, optional)')->options(fn () => WorkforceBudget::query()->orderByDesc('id')->limit(200)->pluck('name', 'id')->all()),
                    Textarea::make('notes')->rows(2),
                ])
                ->action(fn (array $data) => CompensationActions::run(fn () => app(CompensationBudgets::class)->create($data, auth()->user()), 'Budget saved as a draft')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('company'))
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('company.name')->label('Company'),
                TextColumn::make('period')->state(fn (CompensationBudget $record) => $record->period_start->toDateString().' – '.$record->period_end->toDateString()),
                TextColumn::make('amount')->numeric(2)->suffix(fn (CompensationBudget $record) => ' '.$record->currency),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'approved' => 'success', 'closed' => 'gray', default => 'info'
                }),
            ])
            ->defaultSort('period_start', 'desc')
            ->recordActions([
                Action::make('measures')->label('Planned vs actual')->icon(Heroicon::OutlinedScale)->color('gray')->modalSubmitAction(false)
                    ->schema(fn (CompensationBudget $record) => collect(app(CompensationBudgets::class)->measures($record))->except(['currency', 'basis'])
                        ->map(fn ($value, $key) => TextEntry::make("m_{$key}")->label(ucfirst(str_replace('_', ' ', $key)))->state(is_float($value) ? number_format($value, 2).' '.$record->currency : (string) $value))->values()->all()),
                Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheck)->color('success')->requiresConfirmation()
                    ->visible(fn (CompensationBudget $record) => $record->status === 'draft' && auth()->user()->can('compensation.approve') && (int) $record->prepared_by !== (int) auth()->id())
                    ->action(fn (CompensationBudget $record) => CompensationActions::run(fn () => app(CompensationBudgets::class)->approve($record, auth()->user()), 'Budget approved')),
                Action::make('close')->label('Close')->icon(Heroicon::OutlinedArchiveBox)->color('danger')
                    ->visible(fn (CompensationBudget $record) => $record->status === 'approved' && auth()->user()->can('compensation.budget'))
                    ->schema([Textarea::make('reason')->required()])
                    ->action(fn (CompensationBudget $record, array $data) => CompensationActions::run(fn () => app(CompensationBudgets::class)->close($record, auth()->user(), $data['reason']), 'Budget closed')),
            ])
            ->emptyStateHeading('No compensation budgets');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCompensationBudgets::route('/')];
    }
}
