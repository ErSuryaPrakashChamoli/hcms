<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\CountryRegion;
use App\Domain\Attendance\Models\HolidayLibraryList;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Support\PlatformOperator;
use App\Support\Geography\Countries;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Normalizer;
use RuntimeException;

/**
 * The platform's fixed region list per country (states, provinces, emirates…), pre-filled with the top-level ISO 3166-2
 * subdivisions. Holiday library uploads may only name active regions, and take their names from here; tenant locations
 * are matched to regions by code or name. Regions are retired, never deleted.
 */
final class CountryRegions
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return array<string, string> the country's active regions: code => name, by name */
    public function active(?string $country): array
    {
        return CountryRegion::query()->active()->where('country_code', strtoupper((string) $country))->orderBy('name')->pluck('name', 'code')->all();
    }

    /** @return Collection<int, CountryRegion> every region of the country, active and retired, by name */
    public function forCountry(?string $country): Collection
    {
        return CountryRegion::query()->where('country_code', strtoupper((string) $country))->orderBy('name')->orderBy('code')->get();
    }

    public function add(string $country, string $code, string $name, ?User $actor): CountryRegion
    {
        PlatformOperator::assert($actor, 'manage regions');
        $country = strtoupper(trim($country));
        if (! Countries::exists($country)) {
            throw new InvalidArgumentException("'{$country}' is not a country: use its ISO 3166-1 two-letter code.");
        }
        $code = self::code($code, $country);
        if (preg_match('/^[A-Z0-9]+(-[A-Z0-9]+)*$/', $code) !== 1 || strlen($code) > 16) {
            throw new InvalidArgumentException('A region code is up to 16 letters or digits (hyphens between them), e.g. MH or NSW.');
        }
        $name = $this->name($name);
        $existing = CountryRegion::query()->where('country_code', $country)->where('code', $code)->first();
        if ($existing !== null) {
            throw new RuntimeException("{$existing->isoCode()} is already on the list as {$existing->name}".($existing->isActive() ? '.' : ' (retired): restore it instead.'));
        }

        $region = CountryRegion::create(['country_code' => $country, 'code' => $code, 'name' => $name, 'status' => CountryRegion::ACTIVE, 'source' => CountryRegion::SOURCE_PLATFORM]);
        $this->record(AuditAction::Create, $region, $actor);

        return $region;
    }

    /** A new name shows everywhere the region is offered, holiday lists included. The code never changes. */
    public function rename(CountryRegion $region, string $name, ?User $actor): void
    {
        PlatformOperator::assert($actor, 'manage regions');
        $name = $this->name($name);
        $before = $region->name;
        if ($name === $before) {
            return;
        }
        DB::transaction(function () use ($region, $name) {
            $region->update(['name' => $name]);
            HolidayLibraryList::query()->where('country_code', $region->country_code)->where('region_code', $region->code)->update(['region_name' => $name]);
        });
        $this->record(AuditAction::Update, $region, $actor, [['field' => 'name', 'before' => $before, 'after' => $name]]);
    }

    /** No new upload may name a retired region; lists already uploaded for it stay as they are. */
    public function retire(CountryRegion $region, ?User $actor): void
    {
        $this->setStatus($region, CountryRegion::RETIRED, $actor);
    }

    public function restore(CountryRegion $region, ?User $actor): void
    {
        $this->setStatus($region, CountryRegion::ACTIVE, $actor);
    }

    /** Add the standard ISO 3166-2 regions missing from the list (all countries, or one). Never renames, restores or removes. */
    public function syncStandard(?string $country, ?User $actor): int
    {
        PlatformOperator::assert($actor, 'manage regions');
        $standard = self::standard();
        if ($country !== null) {
            $standard = array_intersect_key($standard, [strtoupper($country) => true]);
        }

        $added = 0;
        DB::transaction(function () use ($standard, &$added) {
            foreach ($standard as $countryCode => $regions) {
                $known = CountryRegion::query()->where('country_code', $countryCode)->pluck('code')->flip();
                foreach ($regions as $code => $name) {
                    if (! $known->has((string) $code)) {
                        CountryRegion::create(['country_code' => $countryCode, 'code' => (string) $code, 'name' => $name, 'status' => CountryRegion::ACTIVE, 'source' => CountryRegion::SOURCE_ISO]);
                        $added++;
                    }
                }
            }
        });
        if ($added > 0) {
            $this->audit->record(AuditAction::Import, 'attendance', null, [], null, entityLabel: 'Standard regions'.($country !== null ? ' for '.Countries::name($country) : ''),
                actor: $actor, metadata: ['country_code' => $country !== null ? strtoupper($country) : null, 'added' => $added], platform: true);
        }

        return $added;
    }

    /**
     * The active region a free-text value names: its code with or without the country prefix (MH, IN-MH), or its name
     * ignoring case and accents (maharashtra). Tenant locations are matched this way.
     *
     * @param  array<string, string>|null  $regions  the country's active regions, when already loaded
     */
    public function match(?string $country, ?string $value, ?array $regions = null): ?string
    {
        $regions ??= $this->active($country);
        $code = self::code((string) $value, strtoupper((string) $country));
        if ($code === '') {
            return null;
        }
        if (isset($regions[$code])) {
            return $code;
        }
        $wanted = self::comparable((string) $value);
        foreach ($regions as $regionCode => $name) {
            if (self::comparable($name) === $wanted) {
                return (string) $regionCode;
            }
        }

        return null;
    }

    /** Upper-case, without the country prefix: IN-MH and mh are both MH. */
    public static function code(string $value, string $country): string
    {
        $code = strtoupper(trim($value));

        return $country !== '' && str_starts_with($code, $country.'-') ? substr($code, strlen($country) + 1) : $code;
    }

    /** @return array<string, array<string, string>> the bundled ISO 3166-2 top-level regions: country => [code => name] */
    public static function standard(): array
    {
        return require database_path('data/countries/regions.php');
    }

    private static function comparable(string $value): string
    {
        $plain = preg_replace('/\p{Mn}/u', '', (string) Normalizer::normalize($value, Normalizer::FORM_D));

        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $plain)));
    }

    private function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('A region name is 1 to 255 characters.');
        }

        return $name;
    }

    private function setStatus(CountryRegion $region, string $status, ?User $actor): void
    {
        PlatformOperator::assert($actor, 'manage regions');
        if ($region->status === $status) {
            throw new RuntimeException("{$region->isoCode()} is already {$status}.");
        }
        $before = $region->status;
        $region->update(['status' => $status]);
        $this->record(AuditAction::StatusChange, $region, $actor, [['field' => 'status', 'before' => $before, 'after' => $status]]);
    }

    /** @param  list<array{field: string, before: mixed, after: mixed}>  $changes */
    private function record(AuditAction $action, CountryRegion $region, ?User $actor, array $changes = []): void
    {
        $this->audit->record($action, 'attendance', null, $changes, null, entityLabel: "Region {$region->isoCode()} {$region->name}", actor: $actor,
            metadata: ['country_code' => $region->country_code, 'code' => $region->code, 'source' => $region->source], platform: true);
    }
}
