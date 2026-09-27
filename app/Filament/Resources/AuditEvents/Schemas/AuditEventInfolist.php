<?php

namespace App\Filament\Resources\AuditEvents\Schemas;

use App\Domain\Audit\Enums\AuditAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AuditEventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('What happened')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('action')->badge()->formatStateUsing(fn (AuditAction $state) => $state->label()),
                        TextEntry::make('module'),
                        TextEntry::make('occurred_at')->dateTime('d M Y, H:i:s'),
                        TextEntry::make('entity_type')->label('Record type')->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : '—'),
                        TextEntry::make('entity_label')->label('Record')->placeholder('—'),
                        TextEntry::make('entity_id')->label('Record id')->placeholder('—'),
                        TextEntry::make('reason')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('approval_reference')->placeholder('—'),
                        TextEntry::make('effective_date')->date()->placeholder('—'),
                    ]),
                Section::make('Field changes')
                    ->schema([
                        RepeatableEntry::make('fieldChanges')
                            ->hiddenLabel()
                            ->columns(3)
                            ->schema([
                                TextEntry::make('field'),
                                TextEntry::make('before')->placeholder('∅'),
                                TextEntry::make('after')->placeholder('∅'),
                            ]),
                    ]),
                Section::make('Who and where')
                    ->columns(3)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('actor_name')->label('Actor')->placeholder('System'),
                        TextEntry::make('actor_roles')->badge()->placeholder('—'),
                        TextEntry::make('source'),
                        TextEntry::make('ip_address')->placeholder('—'),
                        TextEntry::make('user_agent')->placeholder('—')->columnSpan(2),
                        TextEntry::make('request_id')->copyable()->placeholder('—'),
                        KeyValueEntry::make('metadata')->columnSpanFull(),
                    ]),
                Section::make('Integrity')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('hash')->copyable()->fontFamily('mono'),
                        TextEntry::make('previous_hash')->copyable()->fontFamily('mono')->placeholder('Genesis'),
                        IconEntry::make('intact')
                            ->label('Hash verified')
                            ->boolean()
                            ->state(fn ($record) => $record->recomputeHash() === $record->hash),
                    ]),
            ]);
    }
}
