<?php

namespace App\Filament\Resources\Tickets\RelationManagers;

use App\Domain\ServiceDesk\Models\TicketComment;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The conversation. Each reader sees only the visibilities CaseAccess allows (employee / internal / restricted), filtered in SQL. */
class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static ?string $title = 'Conversation';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (auth()->user()?->can('view', $ownerRecord) ?? false) && app(CaseAccess::class)->commentVisibilities(auth()->user(), $ownerRecord) !== [];
    }

    public function table(Table $table): Table
    {
        $visible = app(CaseAccess::class)->commentVisibilities(auth()->user(), $this->getOwnerRecord());

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('author')->whereIn('visibility', $visible === [] ? ['none'] : $visible))
            ->columns([
                TextColumn::make('created_at')->label('When')->since(),
                TextColumn::make('author.name')->label('From')->placeholder('System')
                    ->description(fn (TicketComment $record) => $record->visibility === 'employee' ? null : config("peopleos.servicedesk.comment_visibilities.{$record->visibility}")),
                TextColumn::make('body')->wrap(),
                TextColumn::make('attachment_name')->label('Attachment')->placeholder('—')->url(fn (TicketComment $record) => app(ServiceDesk::class)->attachmentUrl($record))->openUrlInNewTab(),
            ])
            ->emptyStateHeading('No messages yet')
            ->defaultSort('id')
            ->paginated(false);
    }
}
