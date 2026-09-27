<?php

namespace App\Filament\Resources\Forms\RelationManagers;

use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Configuration\Models\FormVersion;
use App\Filament\Support\AuditReasonField;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** The builder itself: edit the draft's fields; published versions are read-only. */
class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Versions & fields';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Repeater::make('fields')
                ->label('Fields')
                ->schema([
                    TextInput::make('label')->required()->maxLength(255)->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, callable $set, Get $get) => filled($get('key')) ?: $set('key', Str::snake(Str::limit($state, 40, '')))),
                    TextInput::make('key')->required()->maxLength(64)->regex('/^[a-z][a-z0-9_]*$/')->distinct(),
                    Select::make('type')->options(FormVersion::FIELD_TYPES)->required()->live(),
                    Toggle::make('required'),
                    TextInput::make('help_text')->maxLength(255),
                    Repeater::make('options')
                        ->schema([
                            TextInput::make('value')->required()->maxLength(64),
                            TextInput::make('label')->required()->maxLength(255),
                        ])
                        ->columns(2)
                        ->visible(fn (Get $get) => in_array($get('type'), ['dropdown', 'radio'], true))
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->reorderable()
                ->collapsible()
                ->itemLabel(fn (array $state) => $state['label'] ?? null)
                ->columnSpanFull(),
            AuditReasonField::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version')->formatStateUsing(fn ($state) => "v{$state}")->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('fields')->label('Fields')->state(fn (FormVersion $record) => count($record->fields ?? [])),
                TextColumn::make('publisher.name')->label('Published by')->placeholder('—'),
                TextColumn::make('published_at')->dateTime()->placeholder('—'),
                TextColumn::make('retired_at')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->defaultSort('version', 'desc')
            ->recordActions([
                EditAction::make()
                    ->label('Edit fields')
                    ->visible(fn (FormVersion $record) => $record->status === VersionStatus::Draft)
                    ->modalWidth('4xl')
                    ->using(function (FormVersion $record, array $data) {
                        $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                        return $record;
                    }),
            ]);
    }
}
