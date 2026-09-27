<?php

namespace App\Filament\Resources\Policies\RelationManagers;

use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Configuration\Services\Policies;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\PolicySettingsSchema;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Versions & settings';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema(PolicySettingsSchema::components($this->getOwnerRecord()->type)),
            TextInput::make('change_note')->label('What changed')->maxLength(255)->columnSpanFull(),
            AuditReasonField::make(),
        ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            KeyValueEntry::make('settings')->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version')->formatStateUsing(fn ($state) => "v{$state}")->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('effective_from')->date()->placeholder('—'),
                TextColumn::make('effective_to')->date()->placeholder('Open'),
                TextColumn::make('change_note')->placeholder('—')->wrap(),
                TextColumn::make('publisher.name')->label('Published by')->placeholder('—')->toggleable(),
                TextColumn::make('published_at')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->defaultSort('version', 'desc')
            ->recordActions([
                ViewAction::make()->label('Settings'),
                EditAction::make()
                    ->label('Edit draft')
                    ->visible(fn (PolicyVersion $record) => $record->status === VersionStatus::Draft)
                    ->using(function (PolicyVersion $record, array $data) {
                        $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                        return $record;
                    }),
                Action::make('restore')
                    ->label('Restore as draft')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->visible(fn (PolicyVersion $record) => $record->status !== VersionStatus::Draft && $this->getOwnerRecord()->draft()->doesntExist())
                    ->requiresConfirmation()
                    ->schema([AuditReasonField::make()])
                    ->action(fn (PolicyVersion $record, array $data) => app(Policies::class)->restore($record, $data[AuditReasonField::NAME] ?? null)),
            ]);
    }
}
