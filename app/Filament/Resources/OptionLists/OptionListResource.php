<?php

namespace App\Filament\Resources\OptionLists;

use App\Domain\Configuration\Models\OptionList;
use App\Domain\Configuration\Services\OptionLists;
use App\Filament\Resources\OptionLists\Pages\ManageOptionLists;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\TenantUnique;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * HROne setup parity CA-3 (Employee → Relationship; Document → manage document type): the organisation's additions to
 * built-in vocabularies. A built-in code renames or hides that option; a new code adds one.
 */
class OptionListResource extends Resource
{
    protected static ?string $model = OptionList::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'People Setup';

    protected static ?string $navigationLabel = 'Option lists';

    protected static ?string $modelLabel = 'option';

    protected static ?int $navigationSort = 95;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('list_key')->label('List')->options(collect(OptionLists::LISTS)->map(fn (array $l) => $l['label'])->all())->required()->native(false)->live()->disabledOn('edit'),
            TextInput::make('code')->required()->maxLength(48)->regex('/^[a-z0-9_]+$/')->disabledOn('edit')
                ->helperText(fn (Get $get) => $get('list_key') ? 'Built-in codes: '.implode(', ', array_keys(OptionLists::builtIn((string) $get('list_key')))).'. Use one to rename or hide it.' : 'Lower-case letters, digits and underscores.')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, Get $get) => TenantUnique::rule()($rule)->where('list_key', $get('list_key'))),
            TextInput::make('label')->required()->maxLength(120),
            TextInput::make('sort_order')->numeric()->default(0),
            Select::make('status')->options(['active' => 'Offered', 'inactive' => 'Hidden'])->default('active')->required()->native(false),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('list_key')->label('List')->badge()->formatStateUsing(fn (string $state) => OptionLists::LISTS[$state]['label'] ?? $state),
                TextColumn::make('code'),
                TextColumn::make('label')->searchable(),
                IconColumn::make('built_in')->label('Built-in')->boolean()->state(fn (OptionList $record) => array_key_exists($record->code, OptionLists::builtIn($record->list_key))),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => $state === 'active' ? 'Offered' : 'Hidden')->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('list_key')->label('List')->options(collect(OptionLists::LISTS)->map(fn (array $l) => $l['label'])->all())])
            ->defaultSort('list_key')
            ->recordActions([
                EditAction::make()->using(function (OptionList $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
                DeleteAction::make()->visible(fn (OptionList $record) => ! array_key_exists($record->code, OptionLists::builtIn($record->list_key))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageOptionLists::route('/')];
    }
}
