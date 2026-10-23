<?php

namespace App\Domain\Attendance\Models;

use App\Support\Geography\Countries;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A region of a country (state, province, emirate…) on the platform's fixed list (CountryRegions). The code has no
 * country prefix (MH for IN-MH). Retired regions stay for history but can no longer be used in new uploads.
 */
#[Fillable(['country_code', 'code', 'name', 'status', 'source'])]
class CountryRegion extends Model
{
    public const ACTIVE = 'active';

    public const RETIRED = 'retired';

    public const SOURCE_ISO = 'iso_3166_2';

    public const SOURCE_PLATFORM = 'platform';

    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** "IN-MH" */
    public function isoCode(): string
    {
        return "{$this->country_code}-{$this->code}";
    }

    /** "Maharashtra, India" */
    public function label(): string
    {
        return "{$this->name}, ".Countries::name($this->country_code);
    }
}
