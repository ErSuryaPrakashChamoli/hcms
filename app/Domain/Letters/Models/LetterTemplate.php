<?php

namespace App\Domain\Letters\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Letter factory template (§40): markdown body with {{ employee.name }}-style variables. */
#[Fillable(['tenant_id', 'name', 'code', 'type', 'subject', 'body', 'requires_approval', 'version', 'status'])]
class LetterTemplate extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active', 'version' => 1];

    protected static function booted(): void
    {
        static::saving(function (self $t): void {
            $t->code = strtoupper(trim((string) $t->code));
            if ($t->exists && ($t->isDirty('body') || $t->isDirty('subject'))) {
                $t->version = ($t->getRawOriginal('version') ?? 1) + 1;
            }
        });
    }

    protected function casts(): array
    {
        return ['requires_approval' => 'boolean', 'version' => 'integer', 'status' => ActiveStatus::class];
    }

    public function auditModule(): string
    {
        return 'letters';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }
}
