<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Organisation\Models\Location;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A geo work location (HP-1): a circle employees may check in from. Tied to a work location (everyone posted
 * there), to one employee (field staff, approved location requests), or to neither (tenant-wide). Shared fences
 * (no employee) stay visible under the access scope; personal ones follow it.
 * HROne setup parity: a location may instead be a polygon (radius-based or polygon-based tracking), and may be valid
 * only between two dates (a temporary approval of a location request).
 */
#[Fillable(['tenant_id', 'name', 'location_id', 'employee_id', 'latitude', 'longitude', 'radius_m', 'shape', 'polygon', 'valid_from', 'valid_until', 'address', 'source', 'status', 'created_by'])]
class GeoFence extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $table = 'attendance_geo_fences';

    public const SHAPES = ['circle' => 'Radius around a point', 'polygon' => 'Polygon (boundary points)'];

    protected $attributes = ['status' => 'active', 'source' => 'hr', 'radius_m' => 200, 'shape' => 'circle'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'radius_m' => 'integer', 'polygon' => 'array', 'valid_from' => 'date', 'valid_until' => 'date'];
    }

    /** In force on a day: active and within its validity dates. */
    public function scopeValidOn(Builder $query, string $day): Builder
    {
        return $query->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $day))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day));
    }

    /** Metres outside the location: 0 when inside the circle or polygon. */
    public function distanceOutside(float $latitude, float $longitude): int
    {
        if ($this->shape !== 'polygon' || count($this->points()) < 3) {
            return max(0, $this->distanceTo($latitude, $longitude) - (int) $this->radius_m);
        }
        if ($this->containsPoint($latitude, $longitude)) {
            return 0;
        }

        return (int) collect($this->points())->map(fn (array $p) => self::metres($latitude, $longitude, $p[0], $p[1]))->min();
    }

    /** The polygon's corners as [latitude, longitude], whether stored as pairs or as {latitude, longitude} rows. @return list<array{0: float, 1: float}> */
    public function points(): array
    {
        return array_values(array_map(fn ($p) => isset($p['latitude']) ? [(float) $p['latitude'], (float) $p['longitude']] : [(float) $p[0], (float) $p[1]], (array) ($this->polygon ?? [])));
    }

    /** Ray casting on latitude/longitude (fine for the sizes of work sites). */
    public function containsPoint(float $latitude, float $longitude): bool
    {
        $points = $this->points();
        $inside = false;
        for ($i = 0, $j = count($points) - 1; $i < count($points); $j = $i++) {
            [$yi, $xi] = $points[$i];
            [$yj, $xj] = $points[$j];
            if ((($yi > $latitude) !== ($yj > $latitude)) && ($longitude < ($xj - $xi) * ($latitude - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return 'Geo location '.$this->name;
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Great-circle distance in metres from this fence's centre. */
    public function distanceTo(float $latitude, float $longitude): int
    {
        return self::metres((float) $this->latitude, (float) $this->longitude, $latitude, $longitude);
    }

    private static function metres(float $fromLatitude, float $fromLongitude, float $latitude, float $longitude): int
    {
        $earth = 6371000.0;
        $dLat = deg2rad($latitude - $fromLatitude);
        $dLng = deg2rad($longitude - $fromLongitude);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($fromLatitude)) * cos(deg2rad($latitude)) * sin($dLng / 2) ** 2;

        return (int) round($earth * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
