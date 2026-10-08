<?php

namespace App\Domain\Grievance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Case file entry: note, evidence, action, decision, or a message to/from the employee. */
#[Fillable(['tenant_id', 'grievance_id', 'author_id', 'type', 'body', 'visible_to_employee', 'attachment_path', 'attachment_name', 'attachment_sha256'])]
class GrievanceNote extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['type' => 'note'];

    protected function casts(): array
    {
        return ['visible_to_employee' => 'boolean'];
    }

    public function auditModule(): string
    {
        return 'grievance';
    }

    public function auditLabel(): string
    {
        return ucfirst($this->type).' on case';
    }

    public function auditSensitiveAttributes(): array
    {
        return ['body'];
    }

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
