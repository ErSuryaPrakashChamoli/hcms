<?php

namespace App\Filament\Resources\StatutoryProfiles;

use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Organisation\Models\Company;
use App\Filament\Resources\StatutoryProfiles\Pages\ManageStatutoryProfiles;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Per legal entity: which statutes apply and the registrations (§32). Rules themselves are platform-owned. */
class StatutoryProfileResource extends Resource
{
    protected static ?string $model = CompanyStatutoryProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Company statutory profiles (legacy)';

    protected static ?int $navigationSort = 50;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('company');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Entity')->columns(3)->schema([
                Select::make('company_id')->label('Company')->required()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated()
                    ->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all())->unique(ignoreRecord: true),
                Select::make('jurisdiction')->options(config('peopleos.compliance.jurisdictions'))->default('IN')->required(),
                Select::make('pt_state')->label('Professional tax state')->options(config('peopleos.compliance.states'))->placeholder('Not registered'),
                Select::make('lwf_state')->label('Labour welfare fund state')->options(config('peopleos.compliance.states'))->placeholder('Same as PT state'),
            ]),
            Section::make('Applicability')->columns(3)->schema([
                Toggle::make('pf_applicable')->label('EPF')->default(true),
                Toggle::make('pf_restrict_to_ceiling')->label('Restrict PF to statutory wage ceiling')->default(true),
                Toggle::make('esi_applicable')->label('ESI')->default(true),
                Toggle::make('pt_applicable')->label('Professional tax')->default(true),
                Toggle::make('lwf_applicable')->label('Labour welfare fund'),
                Toggle::make('tds_applicable')->label('TDS on salary')->default(true),
            ]),
            Section::make('Registrations')->columns(3)->schema([
                TextInput::make('pf_establishment_code')->label('PF establishment code')->maxLength(64),
                TextInput::make('esi_code')->label('ESI code')->maxLength(64),
                TextInput::make('pt_registration')->label('PT registration')->maxLength(64),
                TextInput::make('tan')->label('TAN')->maxLength(16),
                TextInput::make('pan')->label('Company PAN')->maxLength(16),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.name')->label('Company')->sortable(),
                TextColumn::make('pt_state')->label('PT state')->placeholder('—')->formatStateUsing(fn (?string $state) => config("peopleos.compliance.states.{$state}", $state)),
                IconColumn::make('pf_applicable')->label('EPF')->boolean(),
                IconColumn::make('esi_applicable')->label('ESI')->boolean(),
                IconColumn::make('pt_applicable')->label('PT')->boolean(),
                IconColumn::make('lwf_applicable')->label('LWF')->boolean(),
                IconColumn::make('tds_applicable')->label('TDS')->boolean(),
                TextColumn::make('tan')->label('TAN')->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make()->using(function (CompanyStatutoryProfile $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageStatutoryProfiles::route('/')];
    }
}
