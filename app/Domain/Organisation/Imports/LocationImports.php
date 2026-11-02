<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Attendance\Services\CountryRegions;
use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Enums\LocationType;
use App\Domain\Organisation\Imports\Concerns\ReadsMasterRows;
use App\Domain\Organisation\Imports\Concerns\RequiresUpdatePermission;
use App\Domain\Organisation\Models\BusinessRegion;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\Region;
use App\Domain\Organisation\Services\LocationHierarchy;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Data import DI-1 (Core → Branches): work locations by code, with company, type, address, time zone, region and
 * business region. A code on file updates that location; a new code adds one. Never changes where a location sits
 * (its parent branch: the Sub branches upload does that), and a branch keeps its sub branches' company.
 */
class LocationImports extends MasterImports
{
    use ReadsMasterRows, RequiresUpdatePermission;

    public const TYPE = 'locations';

    public static function fields(): array
    {
        return [
            'code' => ['Code', true], 'name' => ['Name', true], 'company_code' => ['Company code', true], 'type' => ['Type', false],
            'address_line_1' => ['Address line 1', false], 'address_line_2' => ['Address line 2', false], 'city' => ['City', false],
            'state_code' => ['State code', false], 'postal_code' => ['Postal code', false], 'country_code' => ['Country code', false],
            'timezone' => ['Time zone', false], 'region_code' => ['Region code', false], 'business_region_code' => ['Business region code', false],
            'status' => ['Status', false],
        ];
    }

    public function label(): string
    {
        return 'Branches upload';
    }

    protected function updatePermission(): string
    {
        return 'organisation.update';
    }

    protected function module(): string
    {
        return 'organisation';
    }

    protected function model(): string
    {
        return Location::class;
    }

    protected function aliases(): array
    {
        return ['branch_code' => 'code', 'branch_name' => 'name', 'location_code' => 'code', 'location_name' => 'name', 'company' => 'company_code', 'location_type' => 'type',
            'address' => 'address_line_1', 'state' => 'state_code', 'pin_code' => 'postal_code', 'pincode' => 'postal_code', 'zip' => 'postal_code', 'country' => 'country_code',
            'timezone' => 'timezone', 'region' => 'region_code', 'zone' => 'region_code', 'business_region' => 'business_region_code'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $attributes = $this->placeAttributes($r, $context, $existing, $errors);
        $attributes['company_id'] = $this->lookup($context, Company::class, $r['company_code'] ?? null, 'Company code', $errors);
        $attributes = array_filter($attributes, fn ($v) => $v !== null);
        $this->checkHierarchy($existing, $attributes, $errors);

        return $attributes;
    }

    /** What a branch and a sub branch row share: name, type, address, time zone, region, business region, status. */
    protected function placeAttributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $country = $this->country($r['country_code'] ?? null, $errors) ?? ($existing === null ? 'IN' : null);
        $type = $r['type'] ?? null;
        $timezone = $this->text($r['timezone'] ?? null, 64, 'Time zone', $errors);
        if ($timezone !== null && ! in_array($timezone, timezone_identifiers_list(), true)) {
            $errors[] = "Time zone '{$timezone}' is not a time zone name such as Asia/Kolkata.";
            $timezone = null;
        }
        $state = $this->text($r['state_code'] ?? null, 64, 'State code', $errors);
        if ($state !== null) {
            // The code on the platform's region list when the value names one (MH, IN-MH or Maharashtra), else as given.
            $state = app(CountryRegions::class)->match($country ?? $existing?->country_code, $state) ?? $state;
            if (mb_strlen($state) > 16) {
                $errors[] = "State code '{$state}' is longer than 16 characters: use the state's code, e.g. MH.";
                $state = null;
            }
        }

        return [
            'name' => $this->text($r['name'] ?? null, 255, 'Name', $errors),
            'type' => $type === null ? ($existing === null ? LocationType::Office->value : null) : $this->choice($type, self::types(), 'Type', $errors),
            'address_line_1' => $this->text($r['address_line_1'] ?? null, 255, 'Address line 1', $errors),
            'address_line_2' => $this->text($r['address_line_2'] ?? null, 255, 'Address line 2', $errors),
            'city' => $this->text($r['city'] ?? null, 255, 'City', $errors),
            'state_code' => $state,
            'postal_code' => $this->text($r['postal_code'] ?? null, 16, 'Postal code', $errors),
            'country_code' => $country,
            'timezone' => $timezone,
            'region_id' => $this->lookup($context, Region::class, $r['region_code'] ?? null, 'Region code', $errors),
            'business_region_id' => $this->lookup($context, BusinessRegion::class, $r['business_region_code'] ?? null, 'Business region code', $errors),
            'status' => $this->status($r['status'] ?? null, $errors),
        ];
    }

    /** @return array<string, string> location type => label */
    protected static function types(): array
    {
        return collect(LocationType::cases())->mapWithKeys(fn (LocationType $t) => [$t->value => $t->getLabel()])->all();
    }

    /** The location rules (same company as the parent branch, no loops) on the row's values, before anything is saved. */
    protected function checkHierarchy(?Model $existing, array $attributes, array &$errors): void
    {
        if ($errors !== [] || ($attributes['parent_location_id'] ?? null) === -1) {
            return;
        }
        $probe = $existing === null ? new Location : clone $existing;
        $probe->fill(array_intersect_key($attributes, array_flip(['company_id', 'parent_location_id'])));
        try {
            app(LocationHierarchy::class)->assertValid($probe);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Code', 'Yes', 'The branch code (letters, digits, - and _; up to 32). A code on file updates that branch.', 'BLR'],
                ['Name', 'Yes', 'The branch name.', 'Bengaluru office'],
                ['Company code', 'Yes', 'Code of the company the branch belongs to (Organisation → Companies).', 'ACME'],
                ['Type', 'No', 'One of: '.implode(', ', array_keys(self::types())).'. Blank for a new branch is office.', 'office'],
                ['Address line 1', 'No', 'Street address.', '12 MG Road'],
                ['Address line 2', 'No', 'Area, landmark.', 'Ashok Nagar'],
                ['City', 'No', 'City or town.', 'Bengaluru'],
                ['State code', 'No', 'The state\'s code on the platform region list (KA, MH …); its name works too and is turned into the code.', 'KA'],
                ['Postal code', 'No', 'PIN or postal code (up to 16 characters).', '560001'],
                ['Country code', 'No', 'Two-letter ISO country code. Blank for a new branch is IN.', 'IN'],
                ['Time zone', 'No', 'A time zone name such as Asia/Kolkata.', 'Asia/Kolkata'],
                ['Region code', 'No', 'Code of the branch\'s region (Organisation → Regions).', 'SOUTH'],
                ['Business region code', 'No', 'Code of the branch\'s business region (Organisation → Business regions).', 'APAC'],
                ['Status', 'No', 'active or inactive. Blank for a new branch is active.', 'active'],
            ],
            'notes' => [
                'Rows are matched on Code (any case). A new code adds a branch; a code on file updates it; a row whose values are already on file is skipped.',
                'Blank cells keep what is on file. The branch\'s parent (for a sub branch) is never changed here: use the Sub branches upload.',
                'Changes to an existing branch follow the Configuration Change Centre when your organisation approves configuration changes.',
            ],
        ];
    }

    protected function examples(): array
    {
        $company = Company::query()->withoutGlobalScope(AccessScope::class)->orderBy('id')->value('code') ?? 'ACME';
        $region = Region::query()->orderBy('id')->value('code') ?? '';
        $businessRegion = BusinessRegion::query()->orderBy('id')->value('code') ?? '';

        return [
            ['BLR', 'Bengaluru office', $company, 'office', '12 MG Road', 'Ashok Nagar', 'Bengaluru', 'KA', '560001', 'IN', 'Asia/Kolkata', $region, $businessRegion, 'active'],
            ['PUN-PLANT', 'Pune plant', $company, 'factory', 'Plot 7, MIDC', 'Chakan', 'Pune', 'MH', '410501', 'IN', 'Asia/Kolkata', $region, $businessRegion, 'active'],
        ];
    }
}
