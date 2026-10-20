<?php

namespace App\Filament\Resources\Grievances\RelationManagers;

use App\Domain\Grievance\Models\GrievanceNote;
use App\Domain\Grievance\Services\Grievances;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The case file. The employee sees only entries marked visible to them. */
class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    protected static ?string $title = 'Case file';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        $handler = auth()->user()->can('update', $this->getOwnerRecord());

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('author')->when(! $handler, fn ($q) => $q->where('visible_to_employee', true)))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime(),
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.grievance.note_types.{$state}", $state)),
                TextColumn::make('author.name')->label('By')->placeholder('—'),
                TextColumn::make('body')->wrap(),
                TextColumn::make('attachment_name')->label('Attachment')->placeholder('—')->url(fn (GrievanceNote $record) => app(Grievances::class)->attachmentUrl($record))->openUrlInNewTab(),
                TextColumn::make('visible_to_employee')->label('Employee sees')->formatStateUsing(fn ($state) => $state ? 'Yes' : '—')->visible($handler),
            ])
            ->defaultSort('id')
            ->paginated(false);
    }
}
