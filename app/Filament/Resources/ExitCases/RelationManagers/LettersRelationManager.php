<?php

namespace App\Filament\Resources\ExitCases\RelationManagers;

use App\Domain\Letters\Models\Letter;
use App\Filament\Resources\Letters\LetterResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Letters generated from this exit (source = the case). */
class LettersRelationManager extends RelationManager
{
    protected static string $relationship = 'employee';

    protected static ?string $title = 'Letters';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('letter.issue') || auth()->user()?->can('letter.view');
    }

    public function table(Table $table): Table
    {
        $case = $this->getOwnerRecord();

        return $table
            ->query(fn (): Builder => Letter::query()->where('employee_id', $case->employee_id))
            ->columns([
                TextColumn::make('number'),
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.letters.types.{$state}", $state)),
                TextColumn::make('subject')->limit(50),
                TextColumn::make('status')->badge()->color(fn (string $state) => LetterResource::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.letters.statuses.{$state}", $state)),
                TextColumn::make('issued_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([Action::make('open')->label('Open')->url(fn (Letter $record) => LetterResource::getUrl('view', ['record' => $record]))]);
    }
}
