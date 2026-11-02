<?php

namespace App\Filament\Resources\ExitCases;

use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\ExitWithdrawalRequest;
use App\Domain\Exit\Policies\ExitCasePolicy;
use App\Domain\Exit\Services\RelievingReview;
use App\Domain\Exit\Support\ClearanceOwners;
use App\Domain\Exit\Support\NoticeShortfall;
use App\Domain\Feedback\Services\FeedbackRequests;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\ExitCases\Pages\ListExitCases;
use App\Filament\Resources\ExitCases\Pages\ViewExitCase;
use App\Filament\Resources\ExitCases\RelationManagers\ClearancesRelationManager;
use App\Filament\Resources\ExitCases\RelationManagers\LettersRelationManager;
use App\Filament\Resources\ExitCases\RelationManagers\SettlementRelationManager;
use App\Filament\Resources\ExitCases\RelationManagers\WithdrawalRequestsRelationManager;
use App\Filament\Support\BulkRecordActions;
use App\Filament\Support\EmployeeFilters;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
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
        // HROne parity WO-11: a clearance owner's out-of-office delegate (P4, exit clearance) sees the owner's cases too.
        $owners = ClearanceOwners::for($user);

        return $query->where(fn (Builder $q) => $q->where('employee_id', $me ?? 0)->orWhere('manager_id', $me ?? 0)
            ->orWhereHas('clearances', fn (Builder $c) => $c->whereIn('owner_user_id', $owners['users'])->orWhereIn('owner_role_id', $owners['roles'])));
    }

    /** HR who reads exit cases (the separation desk's filters and details). */
    public static function isHr(): bool
    {
        return (bool) (auth()->user()?->can('exit.view') || auth()->user()?->can('exit.manage'));
    }

    /** Free-text remarks on the case: HR, or the employee on their own exit. */
    private static function readsDetails(ExitCase $record): bool
    {
        return self::isHr() || ExitCasePolicy::isOwn(auth()->user(), $record);
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
                TextEntry::make('is_rehire_eligible')->label('Rehire')->formatStateUsing(fn ($state, ExitCase $record) => $state ? 'Eligible' : 'Not eligible'.($record->rehireBlockReason ? ' — '.$record->rehireBlockReason->name : '')),
                TextEntry::make('separationReason.name')->label('Separation reason')->placeholder('—')
                    ->formatStateUsing(fn ($state, ExitCase $record) => $state.($record->separationReason?->separation_kind ? ' ('.config('peopleos.people.separation_kinds.'.$record->separationReason->separation_kind).')' : '')),
                TextEntry::make('clearance')->label('Clearance')->state(fn (ExitCase $record) => $record->clearances->where('status', 'cleared')->count().' / '.$record->clearances->count().' cleared'.($record->clearances->where('status', 'blocked')->count() ? ' · '.$record->clearances->where('status', 'blocked')->count().' blocked' : '')),
                TextEntry::make('settlement.status')->label('Settlement')->badge()->color('gray')->placeholder('Not started')->formatStateUsing(fn (?string $state) => config("peopleos.exit.settlement_statuses.{$state}", $state)),
                TextEntry::make('settlement.net_amount')->label('Net F&F')->numeric(2)->placeholder('—')->visible(fn () => auth()->user()->can('exit.settle') || auth()->user()->can('exit.view')),
                TextEntry::make('interview.status')->label('Exit interview')->badge()->color('gray')->placeholder('Not done'),
                // HROne setup parity P13: exit interviews on feedback templates (statuses; answers are read on Feedback).
                TextEntry::make('feedback')->label('Exit interviews (feedback)')->state(fn (ExitCase $record) => app(FeedbackRequests::class)->summary($record))
                    ->visible(fn (ExitCase $record) => app(FeedbackRequests::class)->summary($record) !== null)->columnSpan(2),
                TextEntry::make('reason')->placeholder('—')->columnSpanFull()->visible(fn (ExitCase $record) => auth()->user()->can('exit.view') || auth()->user()->can('exit.manage') || ExitCasePolicy::isOwn(auth()->user(), $record)),
                TextEntry::make('knowledge_transfer_notes')->placeholder('—')->columnSpanFull(),
                // HROne parity WO-11: the approver's reason (apart from the employee's), notice relaxation and shortfall, the
                // settlement month, the rehire comment, the acceptance — each shown only once there is something to show.
                TextEntry::make('approverReason.name')->label('Approver\'s reason')
                    ->formatStateUsing(fn ($state, ExitCase $record) => $state.($record->approverReason?->separation_kind ? ' ('.config('peopleos.people.separation_kinds.'.$record->approverReason->separation_kind).')' : '').($record->approver_remark && self::readsDetails($record) ? ' — '.$record->approver_remark : ''))
                    ->visible(fn (ExitCase $record) => $record->approver_reason_id !== null),
                TextEntry::make('resignation_accepted_at')->label('Resignation accepted')->dateTime()->visible(fn (ExitCase $record) => $record->resignation_accepted_at !== null),
                TextEntry::make('notice_shortfall')->label('Notice shortfall')
                    ->state(fn (ExitCase $record) => ($n = NoticeShortfall::for($record)) !== null ? $n['shortfall_days'].' day(s)'.($n['relaxation_days'] > 0 ? ' (after '.$n['relaxation_days'].' relaxation day(s))' : '') : null)
                    ->visible(fn (ExitCase $record) => NoticeShortfall::days($record) > 0 || (int) $record->notice_relaxation_days > 0),
                TextEntry::make('settlement_month')->label('Settlement month')->date('F Y')->visible(fn (ExitCase $record) => $record->settlement_month !== null),
                TextEntry::make('rehire_comment')->label('Rehire comment')->visible(fn (ExitCase $record) => filled($record->rehire_comment) && self::readsDetails($record))->columnSpanFull(),
            ]),
            // HROne parity WO-11 (§23.6.1 #14): what HR checks before relieving — last payroll, salary on hold, notice, the
            // settlement month and what is still to hand over (a warning, never a block).
            Section::make('Relieving review')->collapsible()->schema([
                TextEntry::make('relieving_review')->hiddenLabel()->state(fn (ExitCase $record) => app(RelievingReview::class)->lines($record, auth()->user()))->listWithLineBreaks(),
            ])->visible(fn (ExitCase $record) => ($record->isOpen() || $record->status === 'completed') && (auth()->user()->can('exit.view') || auth()->user()->can('exit.manage') || auth()->user()->can('exit.settle'))),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('employee.person.full_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee'))->description(fn (ExitCase $record) => $record->employee?->employee_code),
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.exit.types.{$state}", $state)),
                TextColumn::make('last_working_day')->date()->sortable()->color(fn (ExitCase $record) => $record->isOpen() && $record->last_working_day->lte(now()->addDays(7)) ? 'danger' : null),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.exit.statuses.{$state}", $state)),
                TextColumn::make('clearance')->label('Clearance')->state(fn (ExitCase $record) => $record->clearances->whereIn('status', ['cleared', 'na'])->count().' / '.$record->clearances->count()),
                TextColumn::make('settlement.status')->label('F&F')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('manager.person.full_name')->label('Manager')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(config('peopleos.exit.statuses'))->multiple()->default(ExitCase::OPEN), SelectFilter::make('type')->options(config('peopleos.exit.types')),
                // HROne parity WO-2: the shared employee filters, the resignation date and the last working day.
                ...EmployeeFilters::forRegister('employee', 'resignation_date', 'Resignation date', excludeLeavers: false),
                EmployeeFilters::dateRange('last_working_days', 'Last working day', 'last_working_day'),
                // HROne parity WO-11: final clearance ("ready to complete": every stage cleared or n.a.) and withdrawal requests.
                Filter::make('ready')->label('Ready to complete (all stages cleared)')->toggle()
                    ->query(fn (Builder $query) => $query->whereIn('status', ['clearance', 'settlement'])->whereHas('clearances')->whereDoesntHave('clearances', fn (Builder $c) => $c->whereIn('status', ['pending', 'blocked'])))
                    ->visible(fn () => self::isHr()),
                SelectFilter::make('withdrawal')->label('Withdrawal request')->options(ExitWithdrawalRequest::STATUSES)
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $status) => $q->whereHas('withdrawalRequests', fn (Builder $w) => $w->where('status', $status))))
                    ->visible(fn () => self::isHr())])
            ->recordActions([ViewAction::make()->label('Open')])
            // HROne parity WO-1: start clearance or complete the selected exits (each through Exits).
            ->toolbarActions([BulkRecordActions::selected('exit_cases', ['exit_cases.start_clearance', 'exit_cases.complete'])]);
    }

    public static function getRelations(): array
    {
        return [ClearancesRelationManager::class, SettlementRelationManager::class, LettersRelationManager::class,
            // HROne parity WO-11 (§23.6.2): requests to withdraw the resignation (only once there is one).
            WithdrawalRequestsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExitCases::route('/'),
            'view' => ViewExitCase::route('/{record}'),
        ];
    }
}
