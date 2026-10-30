<?php

namespace App\Filament\Resources\ClearanceChecklists;

use App\Domain\Exit\Models\ClearanceChecklist;
use App\Filament\Resources\ClearanceChecklists\Pages\ManageClearanceChecklists;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** HROne setup parity (Separation policy → clearance checklist): the items each clearance stage asks for. */
class ClearanceChecklistResource extends Resource
{
    protected static ?string $model = ClearanceChecklist::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Clearance checklists';

    protected static ?string $modelLabel = 'clearance checklist';

    protected static ?int $navigationSort = 81;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('exit.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(120),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required()->native(false),
            Textarea::make('description')->maxLength(500)->columnSpanFull(),
            Repeater::make('items')->columnSpanFull()->columns(4)->minItems(1)->addActionLabel('Add an item')
                ->schema([
                    Select::make('stage')->options(collect(config('peopleos.exit.clearance_stages'))->map(fn ($s) => $s['name'])->all())->required()->native(false),
                    TextInput::make('item')->required()->maxLength(200),
                    TextInput::make('due_days')->label('Due (days before the last day)')->numeric()->minValue(0),
                    Toggle::make('mandatory')->default(true)->inline(false),
                ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('items')->label('Items')->state(fn (ClearanceChecklist $r) => count($r->items ?? [])),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageClearanceChecklists::route('/')];
    }
}
