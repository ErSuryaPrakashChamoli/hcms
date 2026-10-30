<?php

namespace App\Filament\Resources\FeedbackParticipants;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Models\FeedbackParticipant;
use App\Domain\Performance\Services\FeedbackRoundResults;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Filament\Resources\FeedbackParticipants\Pages\ListFeedbackParticipants;
use App\Filament\Support\Feedback360Actions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HROne setup parity HP-8h (Me → 360 feedback; Inbox → 360 nomination): the 360 nomination sets a person deals with —
 * their own (and their results once the round has closed, as the policy allows), the ones they nominate reviewers for,
 * the ones waiting for their approval, their team's (results after closing) and, for HR, everyone in their scope. Every
 * action is checked again by FeedbackNominations / FeedbackRoundResults.
 */
class FeedbackParticipantResource extends Resource
{
    protected static ?string $model = FeedbackParticipant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = '360 feedback';

    protected static ?string $modelLabel = '360 feedback';

    protected static ?int $navigationSort = 47;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $me = app(PerformanceRelationships::class)->forUser($user);
        $roleIds = $user->roles()->pluck('roles.id')->all();
        $reports = $me === null ? [] : app(PerformanceRelationships::class)->reportIds($me)->map(fn ($id) => (int) $id)->all();
        $scopes = app(AccessScopes::class);
        $hr = FeedbackRoundResults::isHr($user);

        return parent::getEloquentQuery()->withoutGlobalScope(AccessScope::class)->with(['round', 'employee.person', 'nominatorUser', 'nominatorRole'])
            ->where(function (Builder $q) use ($user, $me, $roleIds, $reports, $scopes, $hr) {
                $q->where('employee_id', $me?->id ?? 0)
                    ->orWhere('nominator_user_id', $user->id)
                    ->orWhere(fn (Builder $r) => $r->whereNull('nominator_user_id')->whereIn('nominator_role_id', $roleIds ?: [0]))
                    ->orWhereIn('employee_id', $reports ?: [0]);
                if ($hr) {
                    $scopes->isScoped($user) ? $q->orWhereIn('employee_id', $scopes->employeeKeys($user) ?: [0]) : $q->orWhereRaw('1 = 1');
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('round.name')->label('Round')->wrap(),
                TextColumn::make('employee.person.full_name')->label('Employee'),
                TextColumn::make('round.window_closes_on')->label('Window closes')->date(),
                TextColumn::make('nomination_status')->label('Nominations')->badge()->formatStateUsing(fn (string $state) => config("peopleos.performance.feedback_360.participant_statuses.{$state}", $state)),
                TextColumn::make('answered')->label('Answered / asked')->state(fn (FeedbackParticipant $record) => Feedback360Actions::statusText($record))->wrap(),
                TextColumn::make('round.status')->label('Round')->badge()->formatStateUsing(fn (string $state) => config("peopleos.performance.feedback_360.round_statuses.{$state}", $state)),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions(Feedback360Actions::forParticipant());
    }

    /** The employee record of the person signed in, if any. */
    public static function me(): ?Employee
    {
        return app(PerformanceRelationships::class)->forUser(auth()->user());
    }

    public static function getPages(): array
    {
        return ['index' => ListFeedbackParticipants::route('/')];
    }
}
