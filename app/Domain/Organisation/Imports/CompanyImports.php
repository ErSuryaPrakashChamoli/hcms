<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Attendance\Services\CountryRegions;
use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Organisation\Imports\Concerns\ReadsMasterRows;
use App\Domain\Organisation\Imports\Concerns\RequiresUpdatePermission;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Support\AddressRules;
use App\Support\Geography\Currencies;
use App\Support\Imports\ReferenceGuide;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;

/**
 * Master uploads (Core → Companies): companies (legal entities) by name (the code is optional), with the company form's rules — a country (by name) for
 * every new company, its state and city where the country asks for them (AddressRules), a time zone and a currency
 * (the country's when it has one zone; its currency). Saving a company sets up its legal entity and primary
 * establishment as the form does (Company::created → LegalEntities). Logos, contact person and statutory registrations
 * stay on the company's screen.
 */
final class CompanyImports extends MasterImports
{
    use ReadsMasterRows, RequiresUpdatePermission;

    public const TYPE = 'companies';

    public static function fields(): array
    {
        return ['code' => ['Code', false], 'name' => ['Name', true], 'legal_name' => ['Legal name', false], 'industry' => ['Industry', false],
            'country_code' => ['Country', true], 'state_code' => ['State', false], 'city' => ['City', false], 'timezone' => ['Time zone', false],
            'currency' => ['Currency', false], 'phone' => ['Phone', false], 'status' => ['Status', false]];
    }

    public function label(): string
    {
        return 'Companies upload';
    }

    protected function updatePermission(): string
    {
        return 'company.update';
    }

    protected function module(): string
    {
        return 'organisation';
    }

    protected function model(): string
    {
        return Company::class;
    }

    protected function aliases(): array
    {
        return ['company_code' => 'code', 'company_name' => 'name', 'company' => 'name', 'legal_entity' => 'legal_name', 'registered_name' => 'legal_name',
            'country' => 'country_code', 'state' => 'state_code', 'state_code' => 'state_code', 'timezone' => 'timezone', 'time_zone' => 'timezone'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $code = trim((string) ($r['code'] ?? ''));
        if ($code !== '' && ! preg_match('/^[A-Za-z0-9_-]{1,32}$/', $code)) {
            $errors[] = 'Code may only contain letters, digits, dashes and underscores (up to 32 characters).';
        }
        $given = $this->country($r['country_code'] ?? null, $errors);
        $country = $given ?? $existing?->country_code;

        $state = $this->text($r['state_code'] ?? null, 64, 'State', $errors);
        if ($state !== null) {
            $matched = app(CountryRegions::class)->match($country, $state);
            if ($matched === null && AddressRules::hasState($country)) {
                $errors[] = "State '{$state}' is not a state of {$country}: use its name or code, e.g. Maharashtra or MH.";
            }
            $state = $matched ?? $state;
        }
        $city = $this->text($r['city'] ?? null, 120, 'City', $errors);
        // A new company (or one moving country) needs the state and city its country asks for, as the form does.
        if ($country !== null && ($existing === null || $country !== $existing->country_code)) {
            if ($state === null && AddressRules::stateRequired($country)) {
                $errors[] = AddressRules::stateLabel($country)." is required for a company in {$country}.";
            }
            if ($city === null && AddressRules::cityRequired($country)) {
                $errors[] = "City is required for a company in {$country}.";
            }
        }

        $timezone = $this->text($r['timezone'] ?? null, 64, 'Time zone', $errors);
        if ($timezone !== null && ! in_array($timezone, timezone_identifiers_list(), true)) {
            $errors[] = "Time zone '{$timezone}' is not a time zone name such as Asia/Kolkata.";
            $timezone = null;
        }
        if ($timezone === null && $existing === null && $country !== null) {
            $zones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $country);
            $timezone = count($zones) === 1 ? $zones[0] : null;
        }
        $currency = $this->text($r['currency'] ?? null, 3, 'Currency', $errors);
        if ($currency !== null) {
            $currency = strtoupper($currency);
            if (! array_key_exists($currency, Currencies::all())) {
                $errors[] = "Currency '{$currency}' is not a currency code such as INR or USD.";
                $currency = null;
            }
        }
        if ($currency === null && $existing === null && $country !== null) {
            $currency = Currencies::forCountry($country);
        }

        return array_filter([
            'name' => $this->text($r['name'] ?? null, 255, 'Name', $errors),
            'legal_name' => $this->text($r['legal_name'] ?? null, 255, 'Legal name', $errors),
            'industry' => $this->choice($r['industry'] ?? null, (array) config('peopleos.organisation.industries', []), 'Industry', $errors),
            'country_code' => $given,
            'state_code' => $state,
            'city' => $city,
            'timezone' => $timezone,
            'currency' => $currency,
            'phone' => $this->text($r['phone'] ?? null, 32, 'Phone', $errors),
            'status' => $this->status($r['status'] ?? null, $errors),
        ], fn ($v) => $v !== null);
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Code', 'No', ReferenceGuide::ownCode('company').' Letters, digits, - and _ only.', ''],
                ['Name', 'Yes', 'The company name people see.', 'Markedge Technologies'],
                ['Legal name', 'No', 'The registered name.', 'Markedge Technologies Private Limited'],
                ['Industry', 'No', 'One of: '.implode(', ', array_keys((array) config('peopleos.organisation.industries', []))).'. Blank takes your organisation\'s industry.', 'it_ites'],
                ['Country', 'Yes', 'The country\'s name; its two-letter ISO code works too.', 'India'],
                ['State', 'No', 'The state\'s name; its code works too. Required where the country needs it (India and others).', 'Maharashtra'],
                ['City', 'No', 'Required where the country needs a state.', 'Pune'],
                ['Time zone', 'No', 'e.g. Asia/Kolkata. Blank takes the country\'s when it has one.', 'Asia/Kolkata'],
                ['Currency', 'No', 'Three-letter code. Blank takes the country\'s.', 'INR'],
                ['Phone', 'No', 'Office phone.', '+91 20 4000 1000'],
                ['Status', 'No', 'active or inactive. Blank for a new one is active.', 'active'],
            ],
            'notes' => [
                ReferenceGuide::OWN_CODE_NOTE,
                'A new company is set up with its legal entity and primary establishment, as the company form does.',
                'Blank cells keep what is on file. Logo, contact person and statutory registrations are set on the company\'s own screen.',
                'Changes to an existing company follow the Configuration Change Centre when your organisation approves configuration changes.',
            ],
        ];
    }

    protected function examples(): array
    {
        return [['', 'Markedge Technologies', 'Markedge Technologies Private Limited', 'it_ites', 'India', 'Maharashtra', 'Pune', 'Asia/Kolkata', 'INR', '', 'active'],
            ['', 'FynnEdge Advisory', 'FynnEdge Advisory LLP', 'financial_services', 'India', 'Karnataka', 'Bengaluru', 'Asia/Kolkata', 'INR', '', 'active']];
    }
}
