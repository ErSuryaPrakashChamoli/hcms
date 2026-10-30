<?php

namespace App\Filament\Resources\FeedbackRounds;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Models\FeedbackNomination;
use App\Domain\Performance\Models\FeedbackRound;
use App\Domain\Performance\Services\Feedback360Rules;
use App\Domain\Performance\Services\FeedbackRoundResults;
use App\Filament\Resources\FeedbackRounds\Pages\ListFeedbackRounds;
use App\Filament\Resources\FeedbackRounds\Pages\ViewFeedbackRound;
use App\Filament\Resources\FeedbackRounds\RelationManagers\ParticipantsRelationManager;
use App\Filament\Support\Feedback360Actions;
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
use UnitEnum;

/**
 * HROne setup parity HP-8h (HR Desk → Performance → 360 feedback; "Initiate 360 feedback", "Extend 360"): 360 feedback
 * rounds for HR — launch a round of a 360 policy, follow its nomination sets, extend its window, close or cancel it, and
 * export its results (aggregated, anonymity applied). Reading needs performance.feedback_360 or performance.view.
 */
class FeedbackRoundResource extends Resource
{
    protected static ?string $model = FeedbackRound::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = '360 feedback rounds';

    protected static ?string $modelLabel = '360 feedback round';

    protected static ?int $navigationSort = 46;

    public static function canViewAny(): bool
    {
        return FeedbackRoundResults::isHr(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Round')->columns(4)->schema([
                TextEntry::make('name')->columnSpan(2),
                TextEntry::make('policy.name')->label('360 policy'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => config("peopleos.performance.feedback_360.round_statuses.{$state}", $state)),
                TextEntry::make('period')->label('Period')->state(fn (FeedbackRound $record) => $record->period_start->format('d M Y').' – '.$record->period_end->format('d M Y')),
                TextEntry::make('window')->label('Window')->state(fn (FeedbackRound $record) => $record->window_opens_on->format('d M Y').' – '.$record->window_closes_on->format('d M Y')),
                TextEntry::make('extensions')->label('Extensions'),
                TextEntry::make('cycle.name')->label('Blends into')->placeholder('—'),
                TextEntry::make('sources')->label('Sources')->columnSpan(2)->state(fn (FeedbackRound $record) => collect(Feedback360Rules::relations((array) $record->settings))
                    ->map(fn (string $r) => config("peopleos.performance.feedback_360.relations.{$r}").(Feedback360Rules::anonymous((array) $record->settings, $r) ? ' (anonymous)' : '').' — '.($record->templates[$r]['name'] ?? '—'))->implode(' · ')),
                TextEntry::make('nomination')->label('Nominations')->columnSpan(2)->state(fn (FeedbackRound $record) => 'By '.Feedback360Rules::nominator((array) $record->settings)
                    .', from '.(Feedback360Rules::scope((array) $record->settings) === 'direct_reportees' ? 'direct reportees' : 'across the organisation')
                    .', approval: '.Feedback360Rules::approval((array) $record->settings)),
                TextEntry::make('cancel_reason')->label('Cancelled because')->columnSpanFull()->visible(fn (FeedbackRound $record) => $record->status === 'cancelled'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('policy')->withCount('participants'))
            ->columns([
                TextColumn::make('name')->searchable()->wrap(),
                TextColumn::make('policy.name')->label('360 policy'),
                TextColumn::make('period_start')->label('Period')->state(fn (FeedbackRound $record) => $record->period_start->format('M Y').' – '.$record->period_end->format('M Y')),
                TextColumn::make('window_closes_on')->label('Window')->state(fn (FeedbackRound $record) => $record->window_opens_on->format('d M').' – '.$record->window_closes_on->format('d M Y')),
                TextColumn::make('participants_count')->label('Employees'),
                TextColumn::make('answered')->label('Answered')->state(fn (FeedbackRound $record) => FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->where('feedback_round_id', $record->id)->where('status', 'submitted')->count()
                    .' / '.FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->where('feedback_round_id', $record->id)->whereIn('status', ['requested', 'submitted', 'declined'])->count()),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => config("peopleos.performance.feedback_360.round_statuses.{$state}", $state))
                    ->color(fn (string $state) => match ($state) {
                        'open' => 'info', 'closed' => 'success', default => 'gray'
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(config('peopleos.performance.feedback_360.round_statuses'))])
            ->recordActions([ViewAction::make(), ...Feedback360Actions::forRound()]);
    }

    public static function getRelations(): array
    {
        return [ParticipantsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeedbackRounds::route('/'),
            'view' => ViewFeedbackRound::route('/{record}'),
        ];
    }
}
