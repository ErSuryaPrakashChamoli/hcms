<?php

namespace App\Filament\Resources\ExitCases;

use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Policies\ExitCasePolicy;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\ExitCases\Pages\ListExitCases;
use App\Filament\Resources\ExitCases\Pages\ViewExitCase;
use App\Filament\Resources\ExitCases\RelationManagers\ClearancesRelationManager;
use App\Filament\Resources\ExitCases\RelationManagers\LettersRelationManager;
use App\Filament\Resources\ExitCases\RelationManagers\SettlementRelationManager;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Exit management (§59). HR sees all; employees see their own; managers and clearance owners see what they act on. */
class ExitCaseResource extends Resource
{
    protected static ?string $model = ExitCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightStartOnRectangle;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return auth()->user()?->can('exit.view') || auth()->user()?->can('exit.manage') ? 'Exit' : 'Me';
    }

    public static function getNavigationLabel(): string
    {
        return auth()->user()?->can('exit.view') || auth()->user()?->can('exit.manage') ? 'Exit cases' : 'My exit';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'manager.person', 'clearances', 'settlement']);
        $user = auth()->user();
        if ($user->can('exit.view') || $user->can('exit.manage') || $user->can('exit.settle')) {
            return $query;
        }
        $me = Employee::query()->where('user_id', $user->id)->value('id');
        $roleIds = $user->roles()->pluck('roles.id');

        return $query->where(fn (Builder $q) => $q->where('employee_id', $me ?? 0)->orWhere('manager_id', $me ?? 0)
            ->orWhereHas('clearances', fn (Builder $c) => $c->where('owner_user_id', $user->id)->orWhereIn('owner_role_id', $roleIds)));
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'initiated', 'notice' => 'warning', 'clearance' => 'info', 'settlement' => 'primary', 'completed' => 'success', default => 'gray'
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (ExitCase $record) => "{$record->number} · {$record->employee->person?->full_name}")->columns(4)->schema([
                TextEntry::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.exit.types.{$state}", $state)),
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.exit.statuses.{$state}", $state)),
                TextEntry::make('resignation_date')->label('Resignation / decision')->date()->placeholder('—'),
                TextEntry::make('last_working_day')->date()->weight('bold'),
                TextEntry::make('notice_days')->suffix(' days'),
                TextEntry::make('manager.person.full_name')->label('Manager')->placeholder('—'),
                TextEntry::make('knowledgeTransferTo.person.full_name')->label('Knowledge transfer to')->placeholder('—'),
                TextEntry::make('is_rehire_eligible')->label('Rehire')->formatStateUsing(fn ($state) => $state ? 'Eligible' : 'Not eligible'),
                TextEntry::make('clearance')->label('Clearance')->state(fn (ExitCase $record) => $record->clearances->where('status', 'cleared')->count().' / '.$record->clearances->count().' cleared'.($record->clearances->where('status', 'blocked')->count() ? ' · '.$record->clearances->where('status', 'blocked')->count().' blocked' : '')),
                TextEntry::make('settlement.status')->label('Settlement')->badge()->color('gray')->placeholder('Not started')->formatStateUsing(fn (?string $state) => config("peopleos.exit.settlement_statuses.{$state}", $state)),
                TextEntry::make('settlement.net_amount')->label('Net F&F')->numeric(2)->placeholder('—')->visible(fn () => auth()->user()->can('exit.settle') || auth()->user()->can('exit.view')),
                TextEntry::make('interview.status')->label('Exit interview')->badge()->color('gray')->placeholder('Not done'),
                TextEntry::make('reason')->placeholder('—')->columnSpanFull()->visible(fn (ExitCase $record) => auth()->user()->can('exit.view') || auth()->user()->can('exit.manage') || ExitCasePolicy::isOwn(auth()->user(), $record)),
                TextEntry::make('knowledge_transfer_notes')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('employee.person.full_name')->label('Employee')->searchable(['first_name', 'last_name']),
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.exit.types.{$state}", $state)),
                TextColumn::make('last_working_day')->date()->sortable()->color(fn (ExitCase $record) => $record->isOpen() && $record->last_working_day->lte(now()->addDays(7)) ? 'danger' : null),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.exit.statuses.{$state}", $state)),
                TextColumn::make('clearance')->label('Clearance')->state(fn (ExitCase $record) => $record->clearances->whereIn('status', ['cleared', 'na'])->count().' / '.$record->clearances->count()),
                TextColumn::make('settlement.status')->label('F&F')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('manager.person.full_name')->label('Manager')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(config('peopleos.exit.statuses'))->multiple()->default(ExitCase::OPEN), SelectFilter::make('type')->options(config('peopleos.exit.types'))])
            ->recordActions([ViewAction::make()->label('Open')]);
    }

    public static function getRelations(): array
    {
        return [ClearancesRelationManager::class, SettlementRelationManager::class, LettersRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExitCases::route('/'),
            'view' => ViewExitCase::route('/{record}'),
        ];
    }
}
