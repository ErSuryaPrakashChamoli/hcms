<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Step-by-step run log. */
#[Fillable(['tenant_id', 'workflow_instance_id', 'node_id', 'action', 'actor_id', 'payload', 'created_at'])]
class WorkflowAction extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
