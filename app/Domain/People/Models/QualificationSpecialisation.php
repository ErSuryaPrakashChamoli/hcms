<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** HROne setup parity CA-3: a specialisation of a qualification (e.g. B.Tech → Computer science). */
#[Fillable(['tenant_id', 'qualification_id', 'name', 'status'])]
class QualificationSpecialisation extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    public function auditModule(): string
    {
        return 'people';
    }

    public function auditLabel(): string
    {
        return 'Specialisation '.$this->name;
    }

    public function qualification(): BelongsTo
    {
        return $this->belongsTo(Qualification::class);
    }
}
