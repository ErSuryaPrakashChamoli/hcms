<?php

namespace App\Filament\Resources\NumberSeries;

use App\Domain\Configuration\Models\CodeSeries;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Company;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\NumberSeries\Pages\ManageNumberSeries;
use App\Filament\Support\AuditReasonField;
use App\Support\Numbering\NumberSeries;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HROne setup parity (Forms & templates → Code series): how loans, letters (all or one type), exit cases, alumni
 * requests and tickets are numbered, for the organisation or one company. A code type without a series keeps its
 * built-in format (the one used before). Employee codes keep their own screen (Employee code series).
 */
class NumberSeriesResource extends Resource
{
    /** The company choice for an organisation-wide series (stored as company_id null). */
    public const ALL_COMPANIES = 'all';

    protected static ?string $model = CodeSeries::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    protected static string|UnitEnum|null $navigationGroup = 'People Setup';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Number series';

    protected static ?string $modelLabel = 'number series';

    protected static ?string $pluralModelLabel = 'number series';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('company');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Code type')->columns(3)->schema(self::codeTypeFields()),
            Section::make('Configure')->columns(3)->schema(self::configureFields()),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    /**
     * The guided creation: Code type → Configure → Review, as in HROne.
     *
     * @return list<Step>
     */
    public static function wizardSteps(): array
    {
        return [
            Step::make('Code type')->description('What is numbered, and where')->columns(3)->schema(self::codeTypeFields()),
            Step::make('Configure')->description('Prefix, digits, suffix and start')->columns(3)->schema(self::configureFields()),
            Step::make('Review')->description('The numbers it will issue')->columns(3)->schema([
                TextEntry::make('review_type')->label('Code type')->state(fn (Get $get) => NumberSeries::types()[$get('key')]['label'] ?? '—'),
                TextEntry::make('review_company')->label('Applies to')->state(fn (Get $get) => self::companyLabel($get('company_id'))),
                TextEntry::make('review_restarts')->label('Numbering restarts')->state(fn (Get $get) => NumberSeries::restarts((string) $get('prefix'), (string) $get('suffix'))),
                TextEntry::make('review_first')->label('First number')->fontFamily(FontFamily::Mono)->weight('bold')->columnSpanFull()
                    ->state(fn (Get $get) => self::firstNumber($get)),
            ]),
        ];
    }

    public static function table(Table $table): Table
    {
        $types = NumberSeries::types();

        return $table
            ->columns([
                TextColumn::make('module')->label('Module')->state(fn (CodeSeries $record) => $types[$record->key]['module'] ?? '—'),
                TextColumn::make('key')->label('Code type')->formatStateUsing(fn (string $state) => $types[$state]['label'] ?? $state)->sortable(),
                TextColumn::make('company.name')->label('Applicable on')->placeholder('All companies (organisation)'),
                TextColumn::make('pattern')->label('Pattern')->fontFamily(FontFamily::Mono)->state(fn (CodeSeries $record) => $record->pattern()),
                TextColumn::make('restarts')->label('Restarts')->state(fn (CodeSeries $record) => NumberSeries::restarts((string) $record->prefix, (string) $record->suffix))->toggleable(),
                TextColumn::make('next')->label('Next number')->fontFamily(FontFamily::Mono)->badge()->color('primary')
                    ->state(fn (CodeSeries $record) => $record->isActive() ? app(NumberSeries::class)->preview($record->key, $record->company_id) : '—'),
                TextColumn::make('status')->badge(),
                TextColumn::make('configured')->label('Configured?')->badge()
                    ->state(fn (CodeSeries $record) => self::configuredLabel(app(NumberSeries::class)->configured($record->key)))
                    ->color(fn (string $state) => $state === 'Yes' ? 'success' : 'warning'),
            ])
            ->filters([
                SelectFilter::make('module')->label('Module')
                    ->options(collect($types)->pluck('module', 'module')->sort()->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereIn('key', array_keys(array_filter($types, fn (array $t) => $t['module'] === $data['value'])))
                        : $query),
                SelectFilter::make('key')->label('Code type')->options(collect($types)->map(fn (array $t) => $t['label'])->all()),
                SelectFilter::make('applicability')->label('Applicability')
                    ->options(['enterprise' => 'Organisation-wide', 'company' => 'One company'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'enterprise' => $query->whereNull('company_id'),
                        'company' => $query->whereNotNull('company_id'),
                        default => $query,
                    }),
            ])
            ->defaultSort('key')
            ->recordActions([
                EditAction::make()->using(function (CodeSeries $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
                DeleteAction::make()->modalDescription('New numbers of this code type will follow the organisation-wide series or the built-in format. Numbers already issued stay as they are.'),
            ]);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ManageNumberSeries::route('/')];
    }

    public static function configuredLabel(string $configured): string
    {
        return match ($configured) {
            'yes' => 'Yes',
            'partial' => 'Missing for some companies',
            'fallback' => 'Uses the letter series',
            default => 'Built-in format',
        };
    }

    /** @return list<Component> */
    private static function codeTypeFields(): array
    {
        return [
            Select::make('key')->label('Code type')->required()->live()->visibleOn('create')->columnSpan(2)->searchable()
                ->options(fn () => collect(NumberSeries::types())->mapWithKeys(fn (array $t, string $key) => [$key => "{$t['module']} · {$t['label']}"])->all())
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                    $type = NumberSeries::types()[$state] ?? null;
                    if ($type !== null && blank($get('prefix'))) {
                        $set('prefix', (string) ($type['prefix'] ?? ''));
                    }
                    if ($type !== null && $type['applicable_on'] !== 'company') {
                        $set('company_id', self::ALL_COMPANIES);
                    }
                }),
            Select::make('company_id')->label('Applies to')->required()->visibleOn('create')
                ->options(fn (Get $get) => [self::ALL_COMPANIES => 'All companies (organisation)']
                    + ((NumberSeries::types()[$get('key')]['applicable_on'] ?? 'company') === 'company' ? Company::query()->orderBy('name')->pluck('name', 'id')->all() : []))
                ->default(self::ALL_COMPANIES)
                ->dehydrateStateUsing(fn ($state) => $state === self::ALL_COMPANIES || blank($state) ? null : (int) $state)
                ->helperText('A company without its own series uses the organisation-wide one.'),
            TextEntry::make('type_label')->label('Code type')->visibleOn('edit')->columnSpan(2)
                ->state(fn (?CodeSeries $record) => NumberSeries::types()[$record?->key]['label'] ?? $record?->key),
            TextEntry::make('company_label')->label('Applies to')->visibleOn('edit')
                ->state(fn (?CodeSeries $record) => $record?->company?->name ?? 'All companies (organisation)'),
        ];
    }

    /** @return list<Component> */
    private static function configureFields(): array
    {
        return [
            TextInput::make('prefix')->maxLength(24)->live(onBlur: true)->placeholder('LN-{YYYY}-')
                ->helperText('Letters, digits, - _ / and {YYYY} {YY} {MM} {FY}. A period in the pattern restarts numbering with each period.'),
            TextInput::make('digits')->label('Number of digits')->required()->integer()->minValue(1)->maxValue(10)->default(5)->live(onBlur: true),
            TextInput::make('suffix')->maxLength(16)->live(onBlur: true)->placeholder('/IN'),
            TextInput::make('start_number')->label('Start at')->required()->integer()->minValue(1)->default(1)->live(onBlur: true)
                ->helperText('Numbering always continues after the highest number already used in the same pattern.'),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active->value)->required()
                ->helperText('An inactive series is skipped: the organisation-wide series or the built-in format applies.'),
        ];
    }

    private static function companyLabel(mixed $companyId): string
    {
        return $companyId === self::ALL_COMPANIES || blank($companyId) ? 'All companies (organisation)' : (string) (Company::query()->whereKey((int) $companyId)->value('name') ?? '—');
    }

    private static function firstNumber(Get $get): string
    {
        $series = new CodeSeries(['key' => (string) $get('key'), 'prefix' => trim((string) $get('prefix')), 'suffix' => trim((string) $get('suffix')),
            'digits' => (int) $get('digits'), 'start_number' => max(1, (int) $get('start_number'))]);
        $series->company_id = $get('company_id') === self::ALL_COMPANIES || blank($get('company_id')) ? null : (int) $get('company_id');
        $problem = isset(NumberSeries::types()[$series->key]) ? app(NumberSeries::class)->problemWith($series) : ['key', 'Choose a code type.'];

        return $problem === null ? app(NumberSeries::class)->previewSeries($series) : $problem[1];
    }
}
