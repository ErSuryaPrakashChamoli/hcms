<?php

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Builders\ImmutableBuilder;
use App\Domain\Audit\Exceptions\ImmutableAuditRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEventChange extends Model
{
    public function newEloquentBuilder($query): ImmutableBuilder
    {
        return new ImmutableBuilder($query);
    }

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableAuditRecordException::because('update'));
        static::deleting(fn () => throw ImmutableAuditRecordException::because('delete'));
    }

    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(AuditEvent::class, 'audit_event_id');
    }
}
