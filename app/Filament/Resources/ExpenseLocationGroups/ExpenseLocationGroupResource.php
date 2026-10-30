<?php

namespace App\Filament\Resources\ExpenseLocationGroups;

use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseLocationGroup;
use App\Domain\Expense\Services\ExpenseLocationGroups;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Resources\ExpenseLocationGroups\Pages\ManageExpenseLocationGroups;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HROne setup parity EX-A (Expense → Location group): "Location groups are used in order to specify different expense
 * limits for different city groups. You may create Tier 1, Tier 2 and Tier 3 location groups." Each group lists states,
 * whole or by city (a place belongs to one group; a city is matched before its state).
 */
class ExpenseLocationGroupResource extends Resource
{
    protected static ?string $model = ExpenseLocationGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Expense & travel';

    protected static ?string $navigationLabel = 'Location groups';

    protected static ?string $modelLabel = 'location group';

    protected static ?int $navigationSort = 30;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('expense.manage') || auth()->user()?->can('expense.view');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('places');
    }

    public static function form(Schema $schema): Schema
    {
        $states = (array) config('peopleos.compliance.states', []);

        return $schema->columns(2)->components([
            TextInput::make('name')->label('Location group name')->required()->maxLength(120)->placeholder('Tier 1'),
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            TextInput::make('sort_order')->numeric()->minValue(0)->default(0),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            Repeater::make('places')->label('Location group details')->columnSpanFull()->columns(3)->defaultItems(1)->addActionLabel('Add a state')
                ->helperText('One row per state. Leave the cities empty for the whole state. A place belongs to one group; a city listed in one group wins over its state in another.')
                ->schema([
                    TextInput::make('country_code')->label('Country')->default('IN')->length(2)->required()->live(onBlur: true),
                    Select::make('state_code')->label('State')->options($states)->searchable()->required()
                        ->visible(fn (Get $get) => strtoupper((string) $get('country_code')) === 'IN'),
                    TextInput::make('state_name')->label('State')->maxLength(64)->required()
                        ->visible(fn (Get $get) => strtoupper((string) $get('country_code')) !== 'IN'),
                    TagsInput::make('cities')->label('Cities')->placeholder('Type a city and press Enter'),
                ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description('Location groups are used to give different expense limits to different groups of cities, e.g. Tier 1, Tier 2 and Tier 3. A place in no group takes the limits set for every other place.')
            ->columns([
                TextColumn::make('name')->label('Location group')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('states')->label('States')->state(fn (ExpenseLocationGroup $record) => count(array_unique($record->places->pluck('state_code')->all()))),
                TextColumn::make('cities')->label('Cities')->state(fn (ExpenseLocationGroup $record) => $record->places->whereNotNull('city')->count()),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                SelectFilter::make('id')->label('Location group')->multiple()->options(fn () => ExpenseLocationGroup::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')->options(ActiveStatus::class),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data, ExpenseLocationGroup $record) => $data + ['places' => self::placesToForm(app(ExpenseLocationGroups::class)->placesFor($record))])
                    ->using(fn (ExpenseLocationGroup $record, array $data) => self::save($record, $data)),
            ]);
    }

    /** Saves through ExpenseLocationGroups (validation, one group per place); a refusal is shown and keeps the form open. */
    public static function save(?ExpenseLocationGroup $record, array $data): ExpenseLocationGroup
    {
        $reason = AuditReasonField::extract($data);
        $places = array_map(fn (array $row) => [
            'country_code' => $row['country_code'] ?? 'IN',
            'state_code' => strtoupper((string) ($row['country_code'] ?? 'IN')) === 'IN' ? ($row['state_code'] ?? null) : ($row['state_name'] ?? null),
            'cities' => $row['cities'] ?? [],
        ], array_values((array) ($data['places'] ?? [])));
        try {
            return app(ExpenseLocationGroups::class)->save($record, $data, $places, $reason);
        } catch (ExpenseRuleViolation $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->persistent()->send();

            throw new Halt;
        }
    }

    /** @return list<array<string, mixed>> */
    private static function placesToForm(array $places): array
    {
        return array_map(fn (array $p) => ['country_code' => $p['country_code'], 'state_code' => $p['country_code'] === 'IN' ? $p['state_code'] : null,
            'state_name' => $p['country_code'] === 'IN' ? null : $p['state_code'], 'cities' => $p['cities']], $places);
    }

    public static function getPages(): array
    {
        return ['index' => ManageExpenseLocationGroups::route('/')];
    }
}
