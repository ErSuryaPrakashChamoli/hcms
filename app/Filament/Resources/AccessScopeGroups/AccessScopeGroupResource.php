<?php

namespace App\Filament\Resources\AccessScopeGroups;

use App\Domain\Identity\Models\AccessScopeGroup;
use App\Domain\Identity\Services\AccessScopeGroups;
use App\Domain\Identity\Services\AccessScopes;
use App\Filament\Resources\AccessScopeGroups\Pages\CreateAccessScopeGroup;
use App\Filament\Resources\AccessScopeGroups\Pages\EditAccessScopeGroup;
use App\Filament\Resources\AccessScopeGroups\Pages\ListAccessScopeGroups;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;
use UnitEnum;

/**
 * HROne parity CA-9 (Access rights → Data access): module data-access groups. Group view = this list (filter by module);
 * employee view = the user form, which lists a person's groups. A group only narrows what its members see in its module
 * (AccessScopes); everything is written through AccessScopeGroups (audited, with a reason).
 */
class AccessScopeGroupResource extends Resource
{
    protected static ?string $model = AccessScopeGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFunnel;

    protected static string|UnitEnum|null $navigationGroup = 'Access';

    protected static ?string $navigationLabel = 'Data access groups';

    protected static ?string $modelLabel = 'data access group';

    protected static ?int $navigationSort = 12;

    public static function form(Schema $schema): Schema
    {
        $units = [];
        foreach (AccessScopeGroups::UNITS as $dimension => $class) {
            $units[] = Select::make("rules.{$dimension}")->label(AccessScopeGroups::LABELS[$dimension])->multiple()->searchable()->preload()
                ->options(fn () => AccessScopeGroups::unitOptions($dimension));
        }

        return $schema->components([
            Section::make('Group')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(120),
                Select::make('module')->options(AccessScopes::modules())->required()->native(false)
                    ->helperText('Workforce narrows what members see about people everywhere; another module narrows only that module\'s records.'),
                Select::make('status')->options(AccessScopeGroup::STATUSES)->default('active')->required()->native(false),
                Textarea::make('description')->maxLength(1000)->columnSpanFull(),
            ]),
            Section::make('Data the group reaches')
                ->description('Members see only these people in the module (and themselves), and never more than their own access scope allows.')
                ->columns(2)->schema([
                    Radio::make('basis')->options(AccessScopeGroup::BASES)->default('organisation')->required()->inline()->live()->columnSpanFull(),
                    ...array_map(fn (Select $field) => $field->visible(fn (Get $get) => $get('basis') !== 'employees'), $units),
                    Toggle::make('include_sub_departments')->label('Departments include the departments below them')
                        ->visible(fn (Get $get) => $get('basis') !== 'employees'),
                    Select::make('rules.employee')->label('Employees')->multiple()->searchable()->columnSpanFull()
                        ->getSearchResultsUsing(fn (string $search) => AccessScopeGroups::employeeOptions(null, $search))
                        ->getOptionLabelsUsing(fn (array $values) => AccessScopeGroups::employeeOptions(array_map('intval', $values)))
                        ->visible(fn (Get $get) => $get('basis') === 'employees'),
                    Select::make('reportees')->label('Members also see')->options(AccessScopeGroup::REPORTEES)->default('none')->required()->native(false),
                ]),
            Section::make('Members')->schema([
                Select::make('members')->label('Users in this group')->multiple()->searchable()->preload()
                    ->options(fn () => AccessScopeGroups::userOptions()),
            ]),
            AuditReasonField::make()->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('module')->badge()->formatStateUsing(fn (string $state) => AccessScopes::modules()[$state] ?? $state),
                TextColumn::make('basis')->formatStateUsing(fn (string $state) => AccessScopeGroup::BASES[$state] ?? $state)->toggleable(),
                TextColumn::make('members_count')->counts('members')->label('Members'),
                TextColumn::make('rules_count')->counts('rules')->label('Values'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
                TextColumn::make('problem')->label('Check')->state(fn (AccessScopeGroup $record) => app(AccessScopeGroups::class)->problems($record)[0] ?? null)
                    ->placeholder('OK')->color('danger')->wrap(),
            ])
            ->filters([
                SelectFilter::make('module')->options(AccessScopes::modules()),
                SelectFilter::make('status')->options(AccessScopeGroup::STATUSES),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                Action::make('deleteGroup')->label('Delete')->icon('heroicon-o-trash')->color('danger')
                    ->authorize(fn (AccessScopeGroup $record) => auth()->user()->can('delete', $record))
                    ->requiresConfirmation()
                    ->modalDescription('Members get back what their own access scope allows. Audited.')
                    ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(500)])
                    ->action(function (AccessScopeGroup $record, array $data): void {
                        try {
                            app(AccessScopeGroups::class)->delete($record, auth()->user(), $data['reason']);
                            Notification::make()->success()->title('Group deleted.')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccessScopeGroups::route('/'),
            'create' => CreateAccessScopeGroup::route('/create'),
            'edit' => EditAccessScopeGroup::route('/{record}/edit'),
        ];
    }
}
