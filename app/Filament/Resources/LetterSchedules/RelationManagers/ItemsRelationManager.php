<?php

namespace App\Filament\Resources\LetterSchedules\RelationManagers;

use App\Domain\Letters\Models\LetterScheduleItem;
use App\Filament\Resources\Letters\LetterResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** HROne parity WO-13: what a letter schedule did for each employee and occurrence. */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Letters';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'letter']))
            ->columns([
                TextColumn::make('employee.person.full_name')->label('Employee')->description(fn (LetterScheduleItem $record) => $record->employee?->employee_code),
                TextColumn::make('occurrence')->label('For')->date(),
                TextColumn::make('outcome')->badge()->formatStateUsing(fn (string $state) => LetterScheduleItem::OUTCOMES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'issued' => 'success', 'generated' => 'primary', 'refused' => 'warning', default => 'danger'
                    }),
                TextColumn::make('letter.number')->label('Letter')->placeholder('—'),
                TextColumn::make('message')->placeholder('—')->wrap(),
                TextColumn::make('created_at')->label('On')->dateTime(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('outcome')->options(LetterScheduleItem::OUTCOMES)])
            ->recordActions([Action::make('open')->label('Open letter')->visible(fn (LetterScheduleItem $record) => $record->letter_id !== null)
                ->url(fn (LetterScheduleItem $record) => LetterResource::getUrl('view', ['record' => $record->letter_id]))]);
    }
}
