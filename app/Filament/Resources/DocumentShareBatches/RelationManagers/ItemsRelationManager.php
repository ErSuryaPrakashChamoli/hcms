<?php

namespace App\Filament\Resources\DocumentShareBatches\RelationManagers;

use App\Domain\Documents\Models\DocumentShareItem;
use App\Filament\Support\DocumentShareActions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * HROne parity WO-14 (§23.7.3 #6–7): what a share did for each row — the employee (inside the viewer's access scope), the
 * file, shared / failed with the reason / unpublished — with "View", "Unpublish" one or the rows ticked.
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Employees';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'document', 'unpublisher']))
            ->columns([
                TextColumn::make('row')->label('Row')->placeholder('—')->toggleable(),
                TextColumn::make('employee_code')->label('Employee code')->placeholder('—')->searchable(),
                TextColumn::make('employee.person.full_name')->label('Employee name')->placeholder('—'),
                TextColumn::make('file_name')->label('File')->state(fn (DocumentShareItem $record) => $record->document?->original_name ?? $record->file_name)->placeholder('—')->wrap(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => DocumentShareItem::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'shared' => 'success', 'failed' => 'danger', 'unpublished' => 'gray', default => 'warning'
                    }),
                TextColumn::make('reason')->label('Reason')->state(fn (DocumentShareItem $record) => $record->status === 'unpublished'
                    ? 'Unpublished '.$record->unpublished_at?->format('j M Y').' by '.($record->unpublisher?->name ?? '—').': '.$record->unpublish_reason
                    : $record->reason)->placeholder('—')->wrap(),
                TextColumn::make('shared_at')->label('Shared on')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->defaultSort('id')
            ->filters([SelectFilter::make('status')->options(DocumentShareItem::STATUSES)])
            ->recordActions([DocumentShareActions::viewDocument(), DocumentShareActions::unpublishItem()])
            ->toolbarActions([DocumentShareActions::unpublishSelected()]);
    }
}
