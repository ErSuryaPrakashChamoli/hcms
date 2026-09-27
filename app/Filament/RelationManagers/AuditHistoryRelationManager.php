<?php

namespace App\Filament\RelationManagers;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Filament\Resources\AuditEvents\AuditEventResource;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * "What changed?" tab for any Auditable record.
 */
class AuditHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'auditEvents';

    protected static ?string $title = 'History';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('audit.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('action')
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('d M Y, H:i:s')->sortable(),
                TextColumn::make('action')->badge()->formatStateUsing(fn (AuditAction $state) => $state->label()),
                TextColumn::make('actor_name')->label('By')->placeholder('System'),
                TextColumn::make('fieldChanges')
                    ->label('Changes')
                    ->state(fn (AuditEvent $record) => $record->fieldChanges
                        ->map(fn ($c) => "{$c->field}: ".($c->before ?? '∅').' → '.($c->after ?? '∅'))
                        ->all())
                    ->listWithLineBreaks()
                    ->limitList(4)
                    ->expandableLimitedList(),
                TextColumn::make('reason')->placeholder('—')->wrap(),
                TextColumn::make('effective_date')->date()->placeholder('—'),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->recordActions([
                ViewAction::make()->url(fn (AuditEvent $record) => AuditEventResource::getUrl('view', ['record' => $record])),
            ])
            ->paginated([10, 25, 50]);
    }
}
