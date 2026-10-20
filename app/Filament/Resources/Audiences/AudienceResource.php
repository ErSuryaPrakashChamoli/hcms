<?php

namespace App\Filament\Resources\Audiences;

use App\Domain\Engagement\Models\Audience;
use App\Domain\Engagement\Services\Audiences;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Audiences\Pages\CreateAudience;
use App\Filament\Resources\Audiences\Pages\EditAudience;
use App\Filament\Resources\Audiences\Pages\ListAudiences;
use App\Filament\Support\AudienceCriteriaSchema;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;
use UnitEnum;

/**
 * Phase 13: reusable audiences for surveys and announcements. Criteria name organisation units inside
 * the preparer's scope; resolution happens in SQL at launch (snapshot). A preview shows a count, never
 * a list of people.
 */
class AudienceResource extends Resource
{
    protected static ?string $model = Audience::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Engagement';

    protected static ?string $navigationLabel = 'Audiences';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Audience')->columns(2)->schema([
                TextInput::make('code')->required()->maxLength(32)->disabledOn('edit'),
                TextInput::make('name')->required()->maxLength(255),
                Textarea::make('description')->rows(2)->columnSpanFull(),
            ]),
            AudienceCriteriaSchema::section('criteria'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable()->wrap(),
                TextColumn::make('criteria')->label('Criteria')->state(fn (Audience $record) => AudienceCriteriaSchema::describe($record->criteria))->wrap(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                Action::make('preview')->label('Count')->icon('heroicon-m-calculator')->color('gray')
                    ->action(function (Audience $record) {
                        try {
                            Notification::make()->info()->title(app(Audiences::class)->preview($record->criteria ?? [], auth()->user()).' employees match today (inside your scope)')->send();
                        } catch (RuntimeException $e) {
                            ServiceDeskActions::refuse($e->getMessage());
                        }
                    }),
                Action::make('deactivate')->label('Deactivate')->icon('heroicon-m-no-symbol')->color('danger')->requiresConfirmation()
                    ->visible(fn (Audience $record) => $record->status === 'active' && auth()->user()->can('update', $record))
                    ->action(fn (Audience $record) => ServiceDeskActions::run(fn () => app(Audiences::class)->deactivate($record, auth()->user()), 'Deactivated')),
            ]);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListAudiences::route('/'), 'create' => CreateAudience::route('/create'), 'edit' => EditAudience::route('/{record}/edit')];
    }
}
