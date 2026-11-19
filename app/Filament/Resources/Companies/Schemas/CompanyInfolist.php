<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Support\AddressRules;
use App\Http\Controllers\ParityFileController;
use App\Support\Geography\Countries;
use App\Support\Geography\Currencies;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('code'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('legal_name')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('country_code')->label('Country')->formatStateUsing(fn (?string $state) => Countries::name($state)),
                        TextEntry::make('state_code')->label(fn (Company $record) => AddressRules::stateLabel($record->country_code))->placeholder('—')
                            ->formatStateUsing(fn (?string $state, Company $record) => ($name = AddressRules::states($record->country_code)[$state] ?? null) !== null ? "{$name} ({$state})" : $state),
                        TextEntry::make('city')->placeholder('—'),
                        TextEntry::make('timezone')->placeholder('—'),
                        TextEntry::make('currency')->placeholder('—')
                            ->formatStateUsing(fn (?string $state) => ($name = Currencies::all()[$state] ?? null) !== null ? "{$state} · {$name}" : $state),
                        TextEntry::make('effective_from')->date()->placeholder('Open'),
                        TextEntry::make('effective_to')->date()->placeholder('Open'),
                        TextEntry::make('updated_at')->dateTime()->label('Last updated'),
                    ]),
                // HROne setup parity CA-2: the company card's contact details, social links and logo.
                Section::make('Contact and brand')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('industry')->formatStateUsing(fn (?string $state) => config("peopleos.organisation.industries.{$state}", $state))->placeholder('—'),
                        TextEntry::make('phone')->placeholder('—'),
                        TextEntry::make('fax')->placeholder('—'),
                        TextEntry::make('contactEmployee.employee_code')->label('Contact person')->placeholder('—')
                            ->formatStateUsing(fn ($state, Company $record) => $record->contactEmployee?->auditLabel()),
                        TextEntry::make('social_links')->label('Social links')->placeholder('—')->columnSpan(2)
                            ->state(fn (Company $record) => collect($record->social_links ?? [])->filter()->map(fn ($url, $network) => config("peopleos.organisation.social_networks.{$network}", $network).': '.$url)->implode(' · ') ?: null),
                        ImageEntry::make('logo')->label('Logo')->height(48)->placeholder('—')
                            ->state(fn (Company $record) => filled($record->logo_path) ? ParityFileController::url('company-logo', $record, 0) : null),
                    ]),
            ]);
    }
}
