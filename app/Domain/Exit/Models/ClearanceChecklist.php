<?php

namespace App\Domain\Exit\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A clearance checklist (HROne setup parity, Separation policy → "Map clearance checklist"): the items each clearance
 * stage asks for. An exit whose policy maps a checklist builds its stages' items from it instead of the defaults.
 */
#[Fillable(['tenant_id', 'name', 'description', 'items', 'status'])]
class ClearanceChecklist extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }

    public function auditModule(): string
    {
        return 'exit';
    }

    public function auditLabel(): string
    {
        return 'Clearance checklist '.$this->name;
    }

    /** @return list<array{item: string, done: bool, mandatory: bool, due_days: ?int}> the checklist's items for one stage */
    public function itemsFor(string $stage): array
    {
        return collect($this->items ?? [])->where('stage', $stage)->map(fn (array $i) => ['item' => (string) $i['item'], 'done' => false, 'mandatory' => (bool) ($i['mandatory'] ?? true), 'due_days' => isset($i['due_days']) ? (int) $i['due_days'] : null])->values()->all();
    }
}
