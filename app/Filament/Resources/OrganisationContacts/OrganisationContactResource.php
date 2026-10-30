<?php

namespace App\Filament\Resources\OrganisationContacts;

use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\OrganisationContact;
use App\Filament\Resources\OrganisationContacts\Pages\ManageOrganisationContacts;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** HROne setup parity CA-2 (Enterprise → Contact details): HR, decision-maker and technical-administrator contacts. */
class OrganisationContactResource extends Resource
{
    protected static ?string $model = OrganisationContact::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    protected static ?string $navigationLabel = 'Organisation contacts';

    protected static ?string $modelLabel = 'contact';

    protected static ?int $navigationSort = 95;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('type')->options(config('peopleos.organisation.contact_types'))->required()->native(false),
            Select::make('company_id')->label('Company')->placeholder('All companies')
                ->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
            TextInput::make('name')->required()->maxLength(120),
            TextInput::make('designation')->maxLength(120),
            TextInput::make('email')->email()->maxLength(255),
            TextInput::make('mobile')->tel()->maxLength(32),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required()->native(false),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('company'))
            ->columns([
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => config("peopleos.organisation.contact_types.{$state}", $state)),
                TextColumn::make('name')->searchable()->description(fn (OrganisationContact $record) => $record->designation),
                TextColumn::make('company.name')->label('Company')->placeholder('All companies'),
                TextColumn::make('email')->placeholder('—'),
                TextColumn::make('mobile')->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('type')->options(config('peopleos.organisation.contact_types'))])
            ->defaultSort('type')
            ->recordActions([
                EditAction::make()->using(function (OrganisationContact $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageOrganisationContacts::route('/')];
    }
}
