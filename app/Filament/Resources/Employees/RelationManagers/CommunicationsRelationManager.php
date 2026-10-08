<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Communication\Models\AnnouncementRead;
use App\Domain\Communication\Models\CommunicationRecipient;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 14 Employee 360: announcements addressed to this employee (Communication owns the snapshot),
 * with delivery and acknowledgement state. Never the message body.
 */
class CommunicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'communicationRecipients';

    protected static ?string $title = 'Communications';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->hasPermission('communication.manage') || $user->hasPermission('communication.approve') || (int) $ownerRecord->user_id === (int) $user->id);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('announcement:id,title,type,published_at,requires_acknowledgement'))
            ->columns([
                TextColumn::make('announcement.title')->label('Announcement')->wrap(),
                TextColumn::make('announcement.type')->label('Type')->badge()->color('gray'),
                TextColumn::make('announcement.published_at')->label('Published')->date()->placeholder('—'),
                TextColumn::make('status')->label('Delivery')->badge(),
                TextColumn::make('acknowledged')->label('Acknowledged')->state(function (CommunicationRecipient $record) {
                    if (! $record->announcement?->requires_acknowledgement) {
                        return '—';
                    }

                    return AnnouncementRead::query()->where('announcement_id', $record->announcement_id)->where('employee_id', $record->employee_id)->value('acknowledged_at')?->toDateString() ?? 'Pending';
                }),
            ])
            ->defaultSort('id', 'desc');
    }
}
