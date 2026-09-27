<?php

namespace App\Filament\Resources\Tickets\RelationManagers;

use App\Domain\ServiceDesk\Models\TicketComment;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use App\Filament\Support\ServiceDeskActions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The conversation. Internal notes are hidden from the employee. */
class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static ?string $title = 'Conversation';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('author')->when(! ServiceDeskActions::isAgent() && $this->getOwnerRecord()->assignee_id !== auth()->id(), fn ($q) => $q->where('is_internal', false)))
            ->columns([
                TextColumn::make('created_at')->label('When')->since(),
                TextColumn::make('author.name')->label('From')->placeholder('System')->description(fn (TicketComment $record) => $record->is_internal ? 'Internal note' : null),
                TextColumn::make('body')->wrap(),
                TextColumn::make('attachment_name')->label('Attachment')->placeholder('—')->url(fn (TicketComment $record) => app(ServiceDesk::class)->attachmentUrl($record))->openUrlInNewTab(),
            ])
            ->defaultSort('id')
            ->paginated(false);
    }
}
