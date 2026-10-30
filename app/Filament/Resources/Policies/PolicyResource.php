<?php

namespace App\Filament\Resources\Policies;

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Pages\PolicyWizard;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Policies\Pages\CreatePolicy;
use App\Filament\Resources\Policies\Pages\EditPolicy;
use App\Filament\Resources\Policies\Pages\ListPolicies;
use App\Filament\Resources\Policies\RelationManagers\RulesRelationManager;
use App\Filament\Resources\Policies\RelationManagers\VersionsRelationManager;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

/** Policy Engine (§43): typed, versioned, effective-dated policies. */
class PolicyResource extends Resource
{
    protected static ?string $model = Policy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Policies';

    protected static ?int $navigationSort = 10;

    protected static ?string $pluralModelLabel = 'policies';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Policy')
                ->columns(2)
                ->schema([
                    Select::make('type')
                        ->options(collect(config('peopleos.policies.types'))->map(fn ($t) => $t['label'])->all())
                        ->required()
                        ->disabled(fn (string $operation) => $operation === 'edit')
                        ->dehydrated(),
                    Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
                    TextInput::make('name')->required()->maxLength(255)->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, callable $set, string $operation) => $operation === 'create' ? $set('code', Str::upper(Str::slug(Str::limit($state, 30, ''), '_'))) : null),
                    TextInput::make('code')->required()->maxLength(64)->alphaDash(),
                    Textarea::make('description')->rows(2)->columnSpanFull(),
                ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => config("peopleos.policies.types.{$state}.label", $state)),
                TextColumn::make('current')->label('Current version')->state(fn (Policy $record) => ($v = $record->versionEffectiveOn()) ? "v{$v->version} from ".$v->effective_from?->toDateString() : null)->placeholder('None published'),
                // HROne setup parity EX-B: an expense policy's grid shows how many categories it offers (listing expense policies).
                TextColumn::make('expense_categories')->label('Categories')
                    ->state(fn (Policy $record) => $record->type === 'expense' ? count((array) ($record->versionEffectiveOn()?->setting('categories', []) ?? [])) : null)
                    ->placeholder('—')
                    ->visible(fn ($livewire) => ($livewire->tableFilters['type']['value'] ?? null) === 'expense'),
                TextColumn::make('assignment_rules_count')->counts('assignmentRules')->label('Rules'),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('type')->options(collect(config('peopleos.policies.types'))->map(fn ($t) => $t['label'])->all()),
                SelectFilter::make('status')->options(ActiveStatus::class),
            ])
            ->defaultSort('name')
            ->recordUrl(fn (Policy $record) => auth()->user()->can('policy.update') ? PolicyWizard::getUrl(['policy' => $record->id]) : self::getUrl('edit', ['record' => $record]))
            ->recordActions([
                Action::make('wizard')->label('Edit')->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn () => auth()->user()->can('policy.update'))
                    ->url(fn (Policy $record) => PolicyWizard::getUrl(['policy' => $record->id])),
                EditAction::make()->label('Versions & rules')->icon(Heroicon::OutlinedClock),
                self::duplicateAction(),
            ]);
    }

    /**
     * HROne setup parity (Clone on a policy card): a copy of the policy's settings as a new draft policy, which applies to
     * nobody until its applicability is chosen; it opens in the wizard.
     */
    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')->label('Duplicate')->icon(Heroicon::OutlinedDocumentDuplicate)->color('gray')
            ->visible(fn () => auth()->user()->can('policy.create'))
            ->modalDescription('The copy keeps the settings as a draft and applies to nobody until you choose who it applies to.')
            ->fillForm(fn (Policy $record) => ['name' => mb_strimwidth('Copy of '.$record->name, 0, 255), 'code' => mb_substr($record->code.'_COPY', 0, 64)])
            ->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('code')->required()->maxLength(64)->alphaDash(),
                AuditReasonField::make(),
            ])
            ->action(function (Policy $record, array $data, Action $action) {
                try {
                    $copy = app(Policies::class)->duplicate($record, $data['name'], $data['code'], (string) AuditReasonField::extract($data), auth()->user());
                    Notification::make()->success()->title("Copied as {$copy->name}")->send();
                    $action->redirect(PolicyWizard::getUrl(['policy' => $copy->id]));
                } catch (ConfigurationException $e) {
                    Notification::make()->danger()->title('Not copied')->body($e->getMessage())->send();
                }
            });
    }

    public static function getRelations(): array
    {
        return [
            VersionsRelationManager::class,
            RulesRelationManager::class,
            AuditHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPolicies::route('/'),
            'create' => CreatePolicy::route('/create'),
            'edit' => EditPolicy::route('/{record}/edit'),
        ];
    }
}
