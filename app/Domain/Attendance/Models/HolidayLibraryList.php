<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\HolidayLibraryStatus;
use App\Domain\Identity\Models\User;
use App\Support\Geography\Countries;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The platform's holidays for one country, region and year (platform configuration; HolidayLibrary). Region '*'
 * is the national list. Only published lists are offered to tenants.
 */
#[Fillable(['country_code', 'region_code', 'region_name', 'year', 'status', 'source', 'uploaded_by', 'uploaded_at', 'published_by', 'published_at'])]
class HolidayLibraryList extends Model
{
    public const NATIONAL = '*';

    protected function casts(): array
    {
        return ['year' => 'integer', 'status' => HolidayLibraryStatus::class, 'uploaded_at' => 'datetime', 'published_at' => 'datetime'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(HolidayLibraryEntry::class)->orderBy('date');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('status', HolidayLibraryStatus::Published->value);
    }

    public function isNational(): bool
    {
        return $this->region_code === self::NATIONAL;
    }

    public function regionLabel(): string
    {
        return $this->isNational() ? 'National' : ($this->region_name ?: $this->region_code);
    }

    /** "India · Maharashtra · 2027" */
    public function label(): string
    {
        return Countries::name($this->country_code)." · {$this->regionLabel()} · {$this->year}";
    }
}
