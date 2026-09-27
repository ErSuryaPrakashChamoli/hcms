<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Subject and body use {{ path.to.value }} placeholders rendered by TemplateRenderer. */
#[Fillable(['tenant_id', 'key', 'name', 'subject', 'body', 'status'])]
class NotificationTemplate extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['status' => ActiveStatus::class];
    }

    public function auditModule(): string
    {
        return 'notifications';
    }

    public function auditLabel(): string
    {
        return $this->key;
    }
}
