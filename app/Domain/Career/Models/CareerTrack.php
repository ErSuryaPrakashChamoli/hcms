<?php

namespace App\Domain\Career\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Phase 9: a career direction (individual contributor, people manager, specialist, leadership…). Management is never the only route. */
#[Fillable(['tenant_id', 'code', 'name', 'track_type', 'description', 'status'])]
class CareerTrack extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(function (self $t) {
            $t->code = strtoupper(trim((string) $t->code));
            if (! array_key_exists($t->track_type, config('peopleos.career.track_types'))) {
                throw new \RuntimeException("Unknown career track type '{$t->track_type}'.");
            }
        });
    }

    public function auditModule(): string
    {
        return 'career';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }
}
