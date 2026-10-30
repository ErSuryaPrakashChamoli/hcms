<?php

namespace App\Filament\Resources\ApiKeys\RelationManagers;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity SV-26 ("Logs → Security"): the key's own audit events — issued, changed (scopes, IP addresses, rate limit,
 * expiry), regenerated, revoked and its call-log exports — with who did it and the reason. Values the audit trail masks
 * stay masked; the secret never appears.
 */
class SecurityEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'auditEvents';

    protected static ?string $title = 'Security';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('action')
            ->modifyQueryUsing(fn ($query) => $query->with('fieldChanges'))
            ->columns([
                TextColumn::make('occurred_at')->label('Date')->dateTime('d M Y, H:i:s')->sortable(),
                TextColumn::make('action')->badge()->formatStateUsing(fn (AuditAction $state) => match ($state) {
                    AuditAction::Create => 'Issued', AuditAction::Update => 'Changed', AuditAction::ApiKeyRegenerated => 'Regenerated',
                    AuditAction::Export => 'Call log exported', default => $state->label(),
                }),
                TextColumn::make('actor_name')->label('User')->placeholder('System'),
                TextColumn::make('fieldChanges')->label('Changes')
                    ->state(fn (AuditEvent $record) => $record->fieldChanges->map(fn ($c) => "{$c->field}: ".($c->before ?? '∅').' → '.($c->after ?? '∅'))->all())
                    ->listWithLineBreaks()->limitList(4)->expandableLimitedList(),
                TextColumn::make('reason')->placeholder('—')->wrap(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
