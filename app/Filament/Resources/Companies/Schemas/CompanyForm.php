<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\CustomFieldsSchema;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('code')
                            ->required()
                            ->maxLength(32)
                            ->alphaDash()
                            ->helperText('Short unique code, e.g. ABC-TECH.'),
                        TextInput::make('legal_name')->maxLength(255)->columnSpanFull(),
                        Select::make('status')
                            ->options(ActiveStatus::class)
                            ->default(ActiveStatus::Active)
                            ->required(),
                        // HROne setup parity CA-2 (Company → Industry): defaults to the organisation's industry (Setup → Core HR).
                        Select::make('industry')->options(config('peopleos.organisation.industries'))->searchable()
                            ->default(fn () => app(SettingsRepository::class)->get('organisation.industry')),
                    ]),
                // HROne setup parity CA-2 (Company → phone, fax, contact person, social handles, logo).
                Section::make('Contact and brand')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextInput::make('phone')->tel()->maxLength(32),
                        TextInput::make('fax')->maxLength(32),
                        Select::make('contact_employee_id')->label('Contact person')->searchable()->placeholder('—')
                            ->options(fn () => CreateEmployee::managerOptions()),
                        ...array_map(fn (string $network, string $label) => TextInput::make("social_links.{$network}")->label($label)->url()->maxLength(255),
                            array_keys(config('peopleos.organisation.social_networks')), array_values(config('peopleos.organisation.social_networks'))),
                        FileUpload::make('logo_path')->label('Logo')->image()->acceptedFileTypes(['image/png', 'image/jpeg'])->maxSize(5120)
                            ->disk(fn () => config('peopleos.documents.disk'))->directory(fn () => Company::logoDirectory())->visibility('private')
                            ->helperText('PNG or JPEG up to 5 MB. Kept privately; shown on the company card.')->columnSpanFull(),
                    ]),
                Section::make('Locale')
                    ->columns(3)
                    ->schema([
                        TextInput::make('country_code')->required()->default('IN')->length(2),
                        TextInput::make('timezone')->placeholder('Asia/Kolkata')->maxLength(64),
                        TextInput::make('currency')->placeholder('INR')->length(3),
                    ]),
                Section::make('Effective dates')
                    ->description('Leave blank for open-ended. Historical records are never overwritten.')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('effective_from')->native(false),
                        DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
                    ]),
                CustomFieldsSchema::formSection(Company::class),
                AuditReasonField::make()->visibleOn('edit'),
            ]);
    }
}
