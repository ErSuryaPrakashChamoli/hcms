<?php

namespace App\Filament\Resources\EmployeeCodeSeries;

use App\Domain\Employment\Models\EmployeeCodeSeries;
use App\Domain\Employment\Services\EmployeeCodeGenerator;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\EmploymentType;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\EmployeeCodeSeries\Pages\ManageEmployeeCodeSeries;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * How new joiners' employee codes are made, per company: prefix + padded number + suffix. The hire wizard takes the next
 * code of the hire's company series (or the default series), or a code typed by hand where the series allows it.
 * HROne setup parity: a series may be limited to one employment type (company + type, company, type, default).
 */
class EmployeeCodeSeriesResource extends Resource
{
    /** The company choice that makes the tenant default series (stored as company_id null). */
    public const ALL_COMPANIES = 'all';

    private const PART = '/^[A-Za-z0-9_-]*$/';

    protected static ?string $model = EmployeeCodeSeries::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    protected static string|UnitEnum|null $navigationGroup = 'People Setup';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Employee code series';

    protected static ?string $modelLabel = 'employee code series';

    protected static ?string $pluralModelLabel = 'employee code series';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['company', 'employmentType']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Applies to')->columns(3)->schema([
                Select::make('company_id')->label('Company')->required()->visibleOn('create')->columnSpan(2)
                    ->options(fn () => [self::ALL_COMPANIES => 'All companies (default series)'] + Company::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn () => EmployeeCodeSeries::query()->whereNull('company_id')->whereNull('employment_type_id')->exists() ? null : self::ALL_COMPANIES)
                    ->dehydrateStateUsing(fn ($state) => $state === self::ALL_COMPANIES ? null : (int) $state)
                    ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        $default = $value === self::ALL_COMPANIES;
                        $type = filled($get('employment_type_id')) ? (int) $get('employment_type_id') : null;
                        if (EmployeeCodeSeries::query()->when($default, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->where('company_id', (int) $value))
                            ->when($type === null, fn ($q) => $q->whereNull('employment_type_id'), fn ($q) => $q->where('employment_type_id', $type))->exists()) {
                            $fail(match (true) {
                                $type !== null => 'There is already a series for this employment type here. Edit that one instead.',
                                $default => 'There is already a default series. Edit that one instead.',
                                default => 'This company already has a series. Edit that one instead.',
                            });
                        }
                    })
                    ->helperText('A company without its own series uses the default series.'),
                // HROne setup parity (code series by employee type).
                Select::make('employment_type_id')->label('Employment type')->visibleOn('create')->placeholder('Every employment type')
                    ->options(fn () => EmploymentType::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText('Only for this employment type (e.g. contractors). Blank: every type without a series of its own.'),
                TextEntry::make('company_label')->label('Company')->visibleOn('edit')->columnSpan(2)
                    ->state(fn (?EmployeeCodeSeries $record) => $record?->company?->name ?? 'All companies (default series)'),
                TextEntry::make('employment_type_label')->label('Employment type')->visibleOn('edit')
                    ->state(fn (?EmployeeCodeSeries $record) => $record?->employmentType?->name ?? 'Every employment type'),
            ]),
            Section::make('Code format')->columns(3)->schema([
                TextInput::make('prefix')->maxLength(16)->regex(self::PART)->live(onBlur: true)->placeholder('MKT-')
                    ->helperText('Letters, digits, - and _. Can be empty.'),
                TextInput::make('digits')->label('Number of digits')->required()->integer()->minValue(1)->maxValue(10)->default(5)->live(onBlur: true)
                    ->helperText('5 gives 00001.'),
                TextInput::make('suffix')->maxLength(16)->regex(self::PART)->live(onBlur: true)->placeholder('-IN')
                    ->helperText('Letters, digits, - and _. Can be empty.')
                    ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        if (strlen((string) $get('prefix')) + (int) $get('digits') + strlen((string) $value) > 32) {
                            $fail('Prefix, digits and suffix together must fit in 32 characters.');
                        }
                    }),
                TextInput::make('next_number')->label('Next number')->required()->integer()->minValue(1)->default(1)->live(onBlur: true)
                    ->helperText('Numbers already used in this pattern are never handed out again: numbering continues after the highest one.'),
                TextEntry::make('first_code')->label('Next code will be')->fontFamily(FontFamily::Mono)->weight('bold')->columnSpan(2)
                    ->state(fn (Get $get) => self::firstCode($get))
                    ->helperText('Shown as it would be issued now, skipping codes already used.'),
                Toggle::make('allow_manual')->label('Allow typing a code by hand when hiring')->default(true)->columnSpanFull()
                    ->helperText('When on, HR can choose “Enter manually” on the hire form. A typed code is checked against every employee of every company before the hire can continue.'),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.name')->label('Company')->placeholder('All companies (default)')->sortable(),
                TextColumn::make('employmentType.name')->label('Employment type')->placeholder('Every type'),
                TextColumn::make('pattern')->label('Pattern')->fontFamily(FontFamily::Mono)->state(fn (EmployeeCodeSeries $record) => $record->pattern()),
                TextColumn::make('next_code')->label('Next code')->fontFamily(FontFamily::Mono)->badge()->color('primary')
                    ->state(fn (EmployeeCodeSeries $record) => app(EmployeeCodeGenerator::class)->preview($record->company_id, $record->employment_type_id)),
                IconColumn::make('allow_manual')->label('Manual codes')->boolean(),
                TextColumn::make('updated_at')->label('Last changed')->since()->toggleable(),
            ])
            ->defaultSort('company_id')
            ->recordActions([
                EditAction::make()->using(function (EmployeeCodeSeries $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
                DeleteAction::make()->modalDescription(fn (EmployeeCodeSeries $record) => $record->company_id === null
                    ? 'Companies without their own series will fall back to the employee.code settings. Codes already issued stay as they are.'
                    : 'New joiners of '.$record->company?->name.' will take codes from the default series. Codes already issued stay as they are.'),
            ]);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ManageEmployeeCodeSeries::route('/')];
    }

    private static function firstCode(Get $get): string
    {
        $prefix = (string) $get('prefix');
        $suffix = (string) $get('suffix');
        $digits = (int) $get('digits');
        if (! preg_match(self::PART, $prefix) || ! preg_match(self::PART, $suffix) || $digits < 1 || $digits > 10) {
            return '—';
        }

        return app(EmployeeCodeGenerator::class)->firstCodeOf(new EmployeeCodeSeries(['prefix' => $prefix, 'suffix' => $suffix, 'digits' => $digits, 'next_number' => max(1, (int) $get('next_number'))]));
    }
}
