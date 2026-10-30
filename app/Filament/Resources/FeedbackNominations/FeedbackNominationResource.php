<?php

namespace App\Filament\Resources\FeedbackNominations;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Models\FeedbackNomination;
use App\Filament\Resources\FeedbackNominations\Pages\ListFeedbackNominations;
use App\Filament\Support\Feedback360Actions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HROne setup parity HP-8h (Inbox → 360 feedback): the 360 feedback asked of the person signed in — give it on the round's
 * form while the window is open, or say they are not taking part. Only their own nominations are listed.
 */
class FeedbackNominationResource extends Resource
{
    protected static ?string $model = FeedbackNomination::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = '360 feedback to give';

    protected static ?string $modelLabel = '360 feedback';

    protected static ?int $navigationSort = 48;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope(AccessScope::class)->with(['round', 'employee.person'])
            ->where('reviewer_user_id', auth()->id())->where('status', '!=', 'draft')->where('status', '!=', 'cancelled');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.person.full_name')->label('About'),
                TextColumn::make('relation')->label('As')->formatStateUsing(fn (string $state) => config("peopleos.performance.feedback_360.relations.{$state}", $state)),
                IconColumn::make('is_anonymous')->label('Anonymous')->boolean(),
                TextColumn::make('round.name')->label('Round')->wrap(),
                TextColumn::make('round.window_closes_on')->label('Due')->date(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => config("peopleos.performance.feedback_360.nomination_statuses.{$state}", $state))
                    ->color(fn (string $state) => match ($state) {
                        'submitted' => 'success', 'requested' => 'warning', default => 'gray'
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions(Feedback360Actions::forNomination());
    }

    public static function getPages(): array
    {
        return ['index' => ListFeedbackNominations::route('/')];
    }
}
