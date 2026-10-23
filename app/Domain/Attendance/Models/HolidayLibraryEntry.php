<?php

namespace App\Domain\Attendance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One holiday in a platform holiday library list. Tenants copy it into a calendar as a Holiday. */
#[Fillable(['holiday_library_list_id', 'date', 'name', 'type', 'is_half_day'])]
class HolidayLibraryEntry extends Model
{
    protected function casts(): array
    {
        return ['date' => 'date', 'is_half_day' => 'boolean'];
    }

    public function libraryList(): BelongsTo
    {
        return $this->belongsTo(HolidayLibraryList::class, 'holiday_library_list_id');
    }
}
