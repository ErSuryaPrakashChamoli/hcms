<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Phase 14 Employee 360: letters (Letters owns them). Letter staff see all; the employee sees issued letters only. */
class LettersRelationManager extends RelationManager
{
    protected static string $relationship = 'letters';

    protected static ?string $title = 'Letters';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->hasPermission('letter.view') || $user->hasPermission('letter.issue') || $user->hasPermission('letter.manage') || (int) $ownerRecord->user_id === (int) $user->id);
    }

    public function table(Table $table): Table
    {
        $staff = auth()->user()?->hasPermission('letter.view') || auth()->user()?->hasPermission('letter.issue') || auth()->user()?->hasPermission('letter.manage');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $staff ? $query : $query->where('status', 'issued'))
            ->columns([
                TextColumn::make('number'),
                TextColumn::make('type')->formatStateUsing(fn (string $state) => config("peopleos.letters.types.{$state}", $state)),
                TextColumn::make('status')->badge(),
                TextColumn::make('issued_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('id', 'desc');
    }
}
