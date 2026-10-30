<?php

namespace App\Filament\Resources\OnboardingTemplates;

use App\Domain\Onboarding\Jobs\IssueOnboardingItems;
use App\Domain\Onboarding\Models\OnboardingTemplate;
use App\Domain\Onboarding\Services\Onboarding;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\OnboardingTemplates\Pages\CreateOnboardingTemplate;
use App\Filament\Resources\OnboardingTemplates\Pages\EditOnboardingTemplate;
use App\Filament\Resources\OnboardingTemplates\Pages\ListOnboardingTemplates;
use App\Filament\Resources\OnboardingTemplates\RelationManagers\ItemsRelationManager;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\RuleConditionsSchema;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class OnboardingTemplateResource extends Resource
{
    protected static ?string $model = OnboardingTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Onboarding templates';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Template')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255)->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $set, string $operation) => $operation === 'create' ? $set('key', Str::slug($state, '_')) : null),
                TextInput::make('key')->required()->maxLength(64)->regex('/^[a-z][a-z0-9_]*$/')->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
                Textarea::make('description')->rows(2)->columnSpanFull(),
                TextInput::make('priority')->numeric()->default(100)->helperText('Lower wins when several templates match an employee.'),
                Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
                // HROne setup parity 08.2 (Induction policy): an induction programme runs as its own plan next to the checklist.
                Select::make('kind')->options(config('peopleos.onboarding.kinds'))->default('onboarding')->required()->native(false)
                    ->helperText('An induction programme starts as its own plan, next to the onboarding checklist.'),
                TextInput::make('starts_days_before_joining')->label('Start this many days before joining')->numeric()->minValue(0)->maxValue(365)
                    ->helperText('Blank: starts when the person enters preboarding (as before), with preboarding items due 3 days before joining.'),
            ]),
            Section::make('Applies to')->description('Leave empty for everyone.')->collapsed()->schema([
                RuleConditionsSchema::repeater()->label('Conditions')->minItems(0)->defaultItems(0),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('key'),
                TextColumn::make('kind')->badge()->color('gray')->formatStateUsing(fn (?string $state) => config("peopleos.onboarding.kinds.{$state}", $state)),
                TextColumn::make('starts_days_before_joining')->label('Starts')->formatStateUsing(fn ($state) => $state.' day(s) before joining')->placeholder('On preboarding'),
                TextColumn::make('priority')->sortable(),
                TextColumn::make('items_count')->counts('items')->label('Items'),
                TextColumn::make('conditions')->label('Applies to')->state(fn (OnboardingTemplate $record) => $record->conditions ? RuleConditionsSchema::describe($record->conditions) : 'Everyone')->wrap(),
                TextColumn::make('plans_count')->counts('plans')->label('Plans'),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('priority')
            ->filters([SelectFilter::make('kind')->options(config('peopleos.onboarding.kinds'))])
            ->recordActions([EditAction::make(), self::issueAction()]);
    }

    /**
     * HROne setup parity P13 ("You want to apply this change to: New employee / All employee"): new joiners get the
     * template as before; issuing to current employees adds the chosen items (those they have not had since the item
     * last changed) as a separate plan, in a queued job.
     */
    public static function issueAction(): Action
    {
        return Action::make('issue')->label('Issue to current employees')->icon('heroicon-m-paper-airplane')->color('gray')
            ->visible(fn () => auth()->user()->can('onboarding.manage'))
            ->schema(fn (OnboardingTemplate $record) => [
                Radio::make('apply_to')->label('You want to apply this to')->options(['new' => 'New employees only (as they join)', 'all' => 'All current employees matching the template'])->default('new')->required()->live(),
                CheckboxList::make('items')->label('Items to issue')->options(fn () => $record->items()->pluck('title', 'id')->all())->default(fn () => $record->items()->pluck('id')->all())
                    ->visible(fn (Get $get) => $get('apply_to') === 'all')->required(fn (Get $get) => $get('apply_to') === 'all')->columns(2),
                AuditReasonField::make(),
            ])
            ->action(function (OnboardingTemplate $record, array $data) {
                if (($data['apply_to'] ?? 'new') !== 'all') {
                    Notification::make()->success()->title('Nothing issued')->body('The template applies to people who join from now on.')->send();

                    return;
                }
                $employees = app(Onboarding::class)->currentEmployeesFor($record);
                IssueOnboardingItems::dispatch($record->id, array_map('intval', (array) $data['items']), $employees, $data[AuditReasonField::NAME] ?? null);
                Notification::make()->success()->title('Issuing to '.count($employees).' employee(s)')->body('Items they already have are left out.')->send();
            });
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOnboardingTemplates::route('/'),
            'create' => CreateOnboardingTemplate::route('/create'),
            'edit' => EditOnboardingTemplate::route('/{record}/edit'),
        ];
    }
}
