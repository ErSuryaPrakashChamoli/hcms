<?php

namespace App\Domain\Organisation\Models;

use App\Domain\Attendance\Services\CountryRegions;
use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Enums\GeoPlaceKind;
use App\Support\Geography\Countries;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Data import DI-1 (Core → States, Districts, Cities): a place on the organisation's own list. A state may name its
 * region on the platform's ISO 3166-2 list (region_code, e.g. MH); a district belongs to a state; a city belongs to a
 * state and optionally to one of that state's districts. The links are checked on every save, whoever saves.
 */
#[Fillable(['tenant_id', 'kind', 'code', 'name', 'country_code', 'region_code', 'state_id', 'district_id', 'status'])]
class GeoPlace extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['country_code' => 'IN', 'status' => 'active'];

    protected static function booted(): void
    {
        static::saving(function (GeoPlace $place): void {
            $attributes = $place->getAttributes();
            $kind = GeoPlaceKind::tryFrom((string) ($attributes['kind'] ?? '')) ?? throw new RuntimeException('A place is a state, a district or a city.');
            if ($place->exists && $place->isDirty('kind')) {
                throw new RuntimeException('The kind of a place does not change: add the place again as the other kind.');
            }
            $country = strtoupper((string) ($attributes['country_code'] ?? ''));
            if (! Countries::exists($country)) {
                throw new RuntimeException("'{$country}' is not a country: use its ISO 3166-1 two-letter code.");
            }
            $place->country_code = $country;
            $stateId = $attributes['state_id'] ?? null;
            $districtId = $attributes['district_id'] ?? null;

            if ($kind === GeoPlaceKind::State) {
                if ($stateId !== null || $districtId !== null) {
                    throw new RuntimeException('A state does not sit in another place.');
                }
                $region = $attributes['region_code'] ?? null;
                if ($region !== null && $region !== '') {
                    $code = app(CountryRegions::class)->match($country, (string) $region);
                    if ($code === null) {
                        throw new RuntimeException("Region code '{$region}' is not an active region of {$country} on the platform's list.");
                    }
                    $place->region_code = $code;
                }

                return;
            }
            if ($place->region_code !== null) {
                $place->region_code = null;
            }
            $state = $stateId === null ? null : static::query()->whereKey($stateId)->first();
            if ($state === null || $state->kind !== GeoPlaceKind::State) {
                throw new RuntimeException("A {$kind->value} belongs to a state.");
            }
            $place->country_code = $state->country_code;
            if ($kind === GeoPlaceKind::District) {
                if ($districtId !== null) {
                    throw new RuntimeException('A district does not sit in another district.');
                }

                return;
            }
            if ($districtId !== null) {
                $district = static::query()->whereKey($districtId)->first();
                if ($district === null || $district->kind !== GeoPlaceKind::District || (int) $district->state_id !== (int) $stateId) {
                    throw new RuntimeException("The city's district must be a district of its state.");
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['kind' => GeoPlaceKind::class, 'status' => ActiveStatus::class];
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(self::class, 'state_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(self::class, 'district_id');
    }

    public function districts(): HasMany
    {
        return $this->hasMany(self::class, 'state_id')->where('kind', GeoPlaceKind::District->value);
    }
}
