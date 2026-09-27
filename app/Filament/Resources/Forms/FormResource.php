<?php

namespace App\Filament\Resources\Forms;

use App\Domain\Configuration\Models\Form;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Forms\Pages\CreateForm;
use App\Filament\Resources\Forms\Pages\EditForm;
use App\Filament\Resources\Forms\Pages\ListForms;
use App\Filament\Resources\Forms\RelationManagers\SubmissionsRelationManager;
use App\Filament\Resources\Forms\RelationManagers\VersionsRelationManager;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

/** Form Builder (§42). Fields are edited on the draft version; publishing freezes them. */
class FormResource extends Resource
{
    protected static ?string $model = Form::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Customisation';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Form')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255)->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, callable $set, string $operation) => $operation === 'create' ? $set('key', Str::slug($state, '_')) : null),
                    TextInput::make('key')->required()->maxLength(64)->regex('/^[a-z][a-z0-9_]*$/')->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
                    Textarea::make('description')->rows(2)->columnSpanFull(),
                    Toggle::make('requires_approval')->label('Submissions need approval'),
                    Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
                ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('key')->searchable(),
                TextColumn::make('published.version')->label('Published')->placeholder('Never')->formatStateUsing(fn ($state) => "v{$state}"),
                IconColumn::make('draft')->label('Draft')->boolean()->state(fn (Form $record) => $record->draft()->exists()),
                IconColumn::make('requires_approval')->label('Approval')->boolean(),
                TextColumn::make('submissions_count')->counts('submissions')->label('Submissions'),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [
            VersionsRelationManager::class,
            SubmissionsRelationManager::class,
            AuditHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListForms::route('/'),
            'create' => CreateForm::route('/create'),
            'edit' => EditForm::route('/{record}/edit'),
        ];
    }
}
